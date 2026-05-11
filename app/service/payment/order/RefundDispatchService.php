<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\runtime\PaymentPluginManager;
use RuntimeException;
use support\Log;
use Throwable;

/**
 * 退款通道派发服务。
 *
 * 负责把已创建的退款单推进到上游退款请求，并将插件返回或异常统一落到
 * 退款生命周期里，避免退款单长期停留在 CREATED。
 */
class RefundDispatchService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param RefundLifecycleService $refundLifecycleService 退款生命周期服务
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
     * @return void
     */
    public function __construct(
        protected RefundLifecycleService $refundLifecycleService,
        protected RefundOrderRepository $refundOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PaymentPluginManager $paymentPluginManager,
        protected PayOrderRiskControlService $payOrderRiskControlService
    ) {
    }

    /**
     * 请求上游通道处理退款。
     *
     * @param RefundOrder|string $refund 退款单或退款号
     * @param bool $isRetry 是否重试
     * @param bool $throwOnFailure 失败时是否抛出异常，供需要同步感知失败的调用方使用
     * @return RefundOrder 最新退款单
     */
    public function dispatch(RefundOrder|string $refund, bool $isRetry = false, bool $throwOnFailure = false): RefundOrder
    {
        $refundOrder = $this->resolveRefundOrder($refund);
        $refundNo = (string) $refundOrder->refund_no;

        try {
            $refundOrder = $isRetry
                ? $this->refundLifecycleService->retryRefund($refundNo, [
                    'reason' => '重新请求上游退款',
                    'last_error' => '',
                ])
                : $this->refundLifecycleService->markRefundProcessing($refundNo, [
                    'reason' => '请求上游退款',
                    'last_error' => '',
                ]);

            if ((int) $refundOrder->status === TradeConstant::REFUND_STATUS_SUCCESS) {
                return $refundOrder;
            }

            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
                return $refundOrder;
            }

            $payOrder = $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
            if (!$payOrder) {
                throw new ResourceNotFoundException('原支付单不存在', ['pay_no' => (string) $refundOrder->pay_no]);
            }
            $this->payOrderRiskControlService->assertNotFrozen($payOrder, '退款派发');

            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $pluginResult = $plugin->refund($this->buildPluginRefundPayload($payOrder, $refundOrder));

            if (!$this->isPluginSuccess($pluginResult)) {
                $message = (string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '退款失败');
                $refundOrder = $this->refundLifecycleService->markRefundFailed($refundNo, [
                    'failed_at' => $this->now(),
                    'last_error' => $message,
                    'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
                    'ext_json' => [
                        'dispatch' => [
                            'plugin_result' => $this->buildResultSnapshot($pluginResult),
                        ],
                    ],
                ]);

                if ($throwOnFailure) {
                    throw new RuntimeException($message);
                }

                return $refundOrder;
            }

            return $this->refundLifecycleService->markRefundSuccess($refundNo, [
                'succeeded_at' => $this->now(),
                'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
                'ext_json' => [
                    'dispatch' => [
                        'plugin_result' => $this->buildResultSnapshot($pluginResult),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[RefundDispatch] 退款请求失败 refund_no=%s error=%s',
                $refundNo,
                $e->getMessage()
            ));

            $latest = $this->markDispatchExceptionFailed($refundNo, $e);
            if ($throwOnFailure) {
                throw $e;
            }

            return $latest;
        }
    }

    /**
     * 解析退款单模型。
     *
     * @param RefundOrder|string $refund 退款单或退款号
     * @return RefundOrder 退款单模型
     */
    private function resolveRefundOrder(RefundOrder|string $refund): RefundOrder
    {
        if ($refund instanceof RefundOrder) {
            return $refund;
        }

        $refundOrder = $this->refundOrderRepository->findByRefundNo($refund);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refund]);
        }

        return $refundOrder;
    }

    /**
     * 构建插件退款请求载荷。
     *
     * @param PayOrder $payOrder 原支付单
     * @param RefundOrder $refundOrder 退款单
     * @return array<string, mixed> 插件退款参数
     */
    private function buildPluginRefundPayload(PayOrder $payOrder, RefundOrder $refundOrder): array
    {
        return [
            'order_id' => (string) $payOrder->pay_no,
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'chan_order_no' => (string) $payOrder->channel_order_no,
            'chan_trade_no' => (string) $payOrder->channel_trade_no,
            'out_trade_no' => (string) ($payOrder->channel_order_no ?: $payOrder->pay_no),
            'refund_no' => (string) $refundOrder->refund_no,
            'out_refund_no' => (string) $refundOrder->merchant_refund_no,
            'refund_amount' => (int) $refundOrder->refund_amount,
            'refund_reason' => (string) $refundOrder->reason,
            'channel_request_no' => (string) $refundOrder->channel_request_no,
            'extra' => (array) ($payOrder->ext_json ?? []),
        ];
    }

    /**
     * 判断插件返回是否表示成功。
     *
     * @param array<string, mixed> $pluginResult 插件结果
     * @return bool 是否成功
     */
    private function isPluginSuccess(array $pluginResult): bool
    {
        return !array_key_exists('success', $pluginResult) || (bool) $pluginResult['success'];
    }

    /**
     * 从插件返回中提取上游退款单号。
     *
     * @param array<string, mixed> $pluginResult 插件结果
     * @return string 上游退款单号
     */
    private function resolveRefundChannelNo(array $pluginResult): string
    {
        foreach (['chan_refund_no', 'refund_no', 'trade_no', 'out_request_no'] as $key) {
            $value = $pluginResult[$key] ?? '';
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * 构建插件返回快照。
     *
     * 原始 raw_data 可能较大或包含敏感响应体，落库时剔除，仅保留定位问题所需字段。
     *
     * @param array<string, mixed> $pluginResult 插件结果
     * @return array<string, mixed> 可落库快照
     */
    private function buildResultSnapshot(array $pluginResult): array
    {
        unset($pluginResult['raw_data']);
        return $pluginResult;
    }

    /**
     * 将派发异常收口为退款失败状态。
     *
     * @param string $refundNo 退款单号
     * @param Throwable $e 异常
     * @return RefundOrder 最新退款单
     */
    private function markDispatchExceptionFailed(string $refundNo, Throwable $e): RefundOrder
    {
        try {
            return $this->refundLifecycleService->markRefundFailed($refundNo, [
                'failed_at' => $this->now(),
                'last_error' => $e->getMessage() !== '' ? $e->getMessage() : '退款请求异常',
                'ext_json' => [
                    'dispatch' => [
                        'exception' => [
                            'message' => $e->getMessage(),
                            'code' => (string) $e->getCode(),
                        ],
                    ],
                ],
            ]);
        } catch (Throwable $markException) {
            $refundOrder = $this->refundOrderRepository->findByRefundNo($refundNo);
            if ($refundOrder) {
                return $refundOrder;
            }

            throw new ValidationException('退款单状态更新失败：' . $markException->getMessage());
        }
    }
}
