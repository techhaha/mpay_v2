<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\PaymentPluginStatusConstant;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\order\PayOrderRiskControlService;
use support\Log;

/**
 * 支付运行时维护服务。
 *
 * 定时进程调用本服务完成通知重试、订单超时和主动查单。
 */
class PaymentRuntimeMaintenanceService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantNotifyDispatcherService $merchantNotifyDispatcherService 商户通知派发服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PaymentTypeService $paymentTypeService 支付方式服务
     * @param PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
     */
    public function __construct(
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService,
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderRiskControlService $payOrderRiskControlService
    ) {
    }

    /**
     * 重试可派发的商户通知。
     *
     * @param int $limit 批量数量
     * @return array<string, int> 执行摘要
     */
    public function retryMerchantNotifies(int $limit = 100): array
    {
        return [
            'dispatched' => $this->merchantNotifyDispatcherService->dispatchRetryableTasks($limit),
        ];
    }

    /**
     * 将已过期的非终态支付单推进为超时。
     *
     * @param int $limit 批量数量
     * @return array<string, int> 执行摘要
     */
    public function timeoutExpiredPayOrders(int $limit = 100): array
    {
        $summary = [
            'scanned' => 0,
            'timeout' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($this->payOrderRepository->listExpiredMutable($this->now(), $limit) as $payOrder) {
            $summary['scanned']++;

            try {
                $this->payOrderLifecycleService->timeoutPayOrder((string) $payOrder->pay_no, [
                    'reason' => '系统定时任务检测到支付单已过期',
                ]);
                $summary['timeout']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                Log::warning(sprintf(
                    '[PaymentRuntimeMaintenance] 支付单超时处理失败 pay_no=%s error=%s',
                    (string) $payOrder->pay_no,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * 主动查询支付中订单并按上游结果推进状态。
     *
     * @param int $limit 批量数量
     * @param int $minAgeSeconds 支付拉起后至少等待秒数
     * @return array<string, int> 执行摘要
     */
    public function syncPayingOrdersByQuery(int $limit = 50, int $minAgeSeconds = 60): array
    {
        $before = date('Y-m-d H:i:s', time() - max(1, $minAgeSeconds));
        $summary = [
            'scanned' => 0,
            'success' => 0,
            'failed' => 0,
            'closed' => 0,
            'pending' => 0,
            'skipped' => 0,
            'error' => 0,
        ];

        foreach ($this->payOrderRepository->listPayingForActiveQuery($before, $limit) as $payOrder) {
            $summary['scanned']++;
            $result = $this->syncOnePayOrderByQuery($payOrder, 'runtime_active_query');
            $status = (string) ($result['status'] ?? 'error');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            } else {
                $summary['error']++;
            }
        }

        return $summary;
    }

    /**
     * 主动查询单笔支付单。
     *
     * 后台人工查单允许查询非支付中订单，用于处理本地失败/关闭/超时后上游实际成功的情况；
     * 查询结果仍然会通过支付单生命周期服务推进，避免绕开平台服务费和业务单同步逻辑。
     *
     * @param string $payNo 支付单号
     * @param string $source 查单来源
     * @return array<string, mixed> 查单结果
     */
    public function syncPayOrderByQuery(string $payNo, string $source = 'admin_manual_query'): array
    {
        $payNo = trim($payNo);
        $payOrder = $payNo !== '' ? $this->payOrderRepository->findByPayNo($payNo) : null;
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        return $this->syncOnePayOrderByQuery($payOrder, $source);
    }

    /**
     * 查询单笔支付单并按结果推进状态。
     *
     * @param PayOrder $payOrder 支付单
     * @param string $source 查单来源
     * @return array<string, mixed> 查单结果
     */
    private function syncOnePayOrderByQuery(PayOrder $payOrder, string $source = 'runtime_active_query'): array
    {
        $payNo = (string) $payOrder->pay_no;
        if ($this->payOrderRiskControlService->isFrozen($payOrder)) {
            return [
                'pay_no' => $payNo,
                'status' => 'skipped',
                'message' => '支付单已冻结，跳过主动查单',
            ];
        }

        try {
            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $result = $plugin->query($this->buildQueryOrder($payOrder));
            $normalized = $this->normalizeQueryResult($payOrder, $result);
            $snapshot = $this->buildQuerySnapshot($normalized, $result, $source);

            if ($normalized['status'] === PaymentPluginStatusConstant::SUCCESS) {
                $this->payOrderLifecycleService->markPaySuccess($payNo, [
                    'channel_order_no' => $normalized['channel_order_no'],
                    'channel_trade_no' => $normalized['channel_trade_no'],
                    'paid_at' => $normalized['paid_at'] ?: null,
                ]);

                return [
                    'pay_no' => $payNo,
                    'status' => 'success',
                    'snapshot' => $snapshot,
                ];
            }

            if ($normalized['status'] === PaymentPluginStatusConstant::CLOSED) {
                $this->payOrderLifecycleService->closePayOrder($payNo, [
                    'reason' => '主动查单返回渠道已关闭',
                ]);

                return [
                    'pay_no' => $payNo,
                    'status' => 'closed',
                    'snapshot' => $snapshot,
                ];
            }

            if ($normalized['status'] === PaymentPluginStatusConstant::FAILED) {
                $this->payOrderLifecycleService->markPayFailed($payNo, [
                    'channel_order_no' => $normalized['channel_order_no'],
                    'channel_trade_no' => $normalized['channel_trade_no'],
                    'channel_error_code' => $normalized['channel_error_code'],
                    'channel_error_msg' => $normalized['channel_error_msg'],
                    'failed_at' => $normalized['failed_at'] ?: null,
                ]);

                return [
                    'pay_no' => $payNo,
                    'status' => 'failed',
                    'snapshot' => $snapshot,
                ];
            }

            return [
                'pay_no' => $payNo,
                'status' => 'pending',
                'snapshot' => $snapshot,
            ];
        } catch (PaymentException $e) {
            $snapshot = $this->recordQueryError($payOrder, $e->getMessage(), (string) $e->getCode(), $source);
            return [
                'pay_no' => $payNo,
                'status' => 'error',
                'snapshot' => $snapshot,
            ];
        } catch (\Throwable $e) {
            $snapshot = $this->recordQueryError($payOrder, $e->getMessage(), 'QUERY_ERROR', $source);
            return [
                'pay_no' => $payNo,
                'status' => 'error',
                'snapshot' => $snapshot,
            ];
        }
    }

    /**
     * 构建插件查单参数。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed> 查单参数
     */
    private function buildQueryOrder(PayOrder $payOrder): array
    {
        return [
            'pay_no' => (string) $payOrder->pay_no,
            'order_id' => (string) $payOrder->pay_no,
            'out_trade_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'trace_no' => (string) $payOrder->trace_no,
            'chan_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'chan_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
            'channel_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'channel_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
            'pay_type_id' => (int) $payOrder->pay_type_id,
            'pay_type_code' => $this->paymentTypeService->resolveCodeById((int) $payOrder->pay_type_id),
            'amount' => (int) $payOrder->pay_amount,
            'pay_amount' => (int) $payOrder->pay_amount,
            'client_ip' => (string) ($payOrder->client_ip ?? ''),
            '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
            'extra' => (array) ($payOrder->ext_json ?? []),
        ];
    }

    /**
     * 归一化插件查单结果。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $result 插件查单结果
     * @return array<string, mixed> 归一化结果
     */
    private function normalizeQueryResult(PayOrder $payOrder, array $result): array
    {
        $statusText = strtolower(trim((string) ($result['status'] ?? $result['trade_status'] ?? $result['channel_status'] ?? '')));
        $success = array_key_exists('success', $result) ? (bool) $result['success'] : null;

        $status = match (true) {
            in_array($statusText, PaymentPluginStatusConstant::successQueryAliases(), true) => PaymentPluginStatusConstant::SUCCESS,
            in_array($statusText, PaymentPluginStatusConstant::closedQueryAliases(), true) => PaymentPluginStatusConstant::CLOSED,
            in_array($statusText, PaymentPluginStatusConstant::failedQueryAliases(), true) => PaymentPluginStatusConstant::FAILED,
            $success === false => PaymentPluginStatusConstant::UNKNOWN,
            default => PaymentPluginStatusConstant::PENDING,
        };

        $channelOrderNo = $this->firstText($result, ['channel_order_no', 'chan_order_no', 'out_trade_no']);
        $channelTradeNo = $this->firstText($result, ['channel_trade_no', 'chan_trade_no', 'trade_no', 'api_trade_no']);
        $channelStatus = trim((string) ($result['channel_status'] ?? $result['status'] ?? ''));
        $message = $this->firstText($result, ['message', 'msg', 'channel_error_msg']);

        return [
            'status' => $status,
            'raw_status' => $statusText,
            'channel_order_no' => $channelOrderNo !== '' ? $channelOrderNo : (string) ($payOrder->channel_order_no ?? ''),
            'channel_trade_no' => $channelTradeNo !== '' ? $channelTradeNo : (string) ($payOrder->channel_trade_no ?? ''),
            'channel_status' => $channelStatus,
            'channel_error_code' => $this->firstText($result, ['channel_error_code', 'code']),
            'channel_error_msg' => $message !== '' ? $message : ($status === PaymentPluginStatusConstant::FAILED ? '主动查单返回支付失败' : ''),
            'paid_at' => $result['paid_at'] ?? null,
            'failed_at' => $result['failed_at'] ?? null,
        ];
    }

    /**
     * 构建本次主动查单的返回快照。
     *
     * @param array<string, mixed> $normalized 归一化结果
     * @param array<string, mixed> $result 插件原始结果
     * @param string $source 查单来源
     * @return array<string, mixed> 快照
     */
    private function buildQuerySnapshot(array $normalized, array $result, string $source = 'runtime_active_query'): array
    {
        return [
            'queried_at' => $this->now(),
            'source' => $source,
            'status' => (string) $normalized['status'],
            'raw_status' => (string) ($normalized['raw_status'] ?? ''),
            'channel_status' => (string) ($normalized['channel_status'] ?? ''),
            'message' => $this->firstText($result, ['message', 'msg']),
            'success' => array_key_exists('success', $result) ? (bool) $result['success'] : null,
            'channel_order_no' => (string) ($normalized['channel_order_no'] ?? ''),
            'channel_trade_no' => (string) ($normalized['channel_trade_no'] ?? ''),
        ];
    }

    /**
     * 构建主动查单异常快照，异常不推进支付状态。
     *
     * @param PayOrder $payOrder 支付单
     * @param string $message 错误信息
     * @param string $code 错误码
     * @param string $source 查单来源
     * @return array<string, mixed> 异常快照
     */
    private function recordQueryError(PayOrder $payOrder, string $message, string $code, string $source = 'runtime_active_query'): array
    {
        Log::warning(sprintf(
            '[PaymentRuntimeMaintenance] 主动查单失败 pay_no=%s code=%s error=%s',
            (string) $payOrder->pay_no,
            $code,
            $message
        ));

        $snapshot = [
            'queried_at' => $this->now(),
            'source' => $source,
            'status' => 'error',
            'raw_status' => '',
            'channel_status' => '',
            'message' => $message,
            'success' => false,
            'error_code' => $code,
            'channel_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'channel_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
        ];
        return $snapshot;
    }

    /**
     * 从候选字段中取首个非空文本。
     *
     * @param array<string, mixed> $data 数据
     * @param array<int, string> $keys 候选字段
     * @return string 文本
     */
    private function firstText(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }
}
