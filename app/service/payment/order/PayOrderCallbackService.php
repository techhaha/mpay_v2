<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\NotifyService;
use app\service\payment\runtime\PaymentPluginManager;
use support\Request;
use support\Response;

/**
 * 支付单回调服务。
 *
 * 负责渠道回调日志记录、插件回调解析和支付状态分发。
 *
 * @property NotifyService $notifyService 通知服务
 * @property PaymentPluginManager $paymentPluginManager 支付插件管理器
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
 */
class PayOrderCallbackService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param NotifyService $notifyService 通知服务
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
     */
    public function __construct(
        protected NotifyService $notifyService,
        protected PaymentPluginManager $paymentPluginManager,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService
    ) {
    }

    /**
     * 处理渠道回调载荷并推进支付状态。
     *
     * @param array $input 回调载荷
     * @return PayOrder 支付订单模型
     * @throws ValidationException
     */
    public function handleChannelCallback(array $input): PayOrder
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空', ['pay_no' => $payNo]);
        }

        // 先落回调日志，后续无论成功还是失败，都可以从统一表里排查。
        $this->notifyService->recordPayCallback([
            'pay_no' => $payNo,
            'channel_id' => (int) ($input['channel_id'] ?? 0),
            'callback_type' => (int) ($input['callback_type'] ?? NotifyConstant::CALLBACK_TYPE_ASYNC),
            'request_data' => $input['request_data'] ?? [],
            'verify_status' => (int) ($input['verify_status'] ?? NotifyConstant::VERIFY_STATUS_UNKNOWN),
            'process_status' => (int) ($input['process_status'] ?? NotifyConstant::PROCESS_STATUS_PENDING),
            'process_result' => $input['process_result'] ?? [],
        ]);

        $success = (bool) ($input['success'] ?? false);
        // 回调链路只根据插件/渠道给出的结果收口支付单状态。
        if ($success) {
            return $this->payOrderLifecycleService->markPaySuccess($payNo, $input);
        }

        return $this->payOrderLifecycleService->markPayFailed($payNo, $input);
    }

    /**
     * 按支付单号处理真实第三方回调。
     *
     * 该方法先定位支付单，再由插件解析原始请求，最后统一交给生命周期服务推进状态。
     *
     * @param string $payNo 支付单号
     * @param Request $request 请求对象
     * @return string|Response 插件要求返回的响应内容
     * @throws ResourceNotFoundException
     */
    public function handlePluginCallback(string $payNo, Request $request): string|Response
    {
        // 回调必须能定位到具体支付单，找不到就直接终止。
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);

        try {
            // 插件必须直接返回标准结构，系统层只负责校验，不再兼容旧字段别名。
            $result = $this->validatePluginNotifyResult($plugin->notify($request));
            $status = (string) $result['status'];

            // 将插件返回值归一化为生命周期服务可消费的回调载荷。
            /** @var array<string, mixed> $callbackPayload */
            $callbackPayload = [
                'pay_no' => $payNo,
                'success' => $status === PaymentPluginStatusConstant::SUCCESS,
                'channel_id' => (int) $payOrder->channel_id,
                'callback_type' => NotifyConstant::CALLBACK_TYPE_ASYNC,
                'request_data' => $request->all(),
                'verify_status' => NotifyConstant::VERIFY_STATUS_SUCCESS,
                'process_status' => $this->resolveProcessStatus($status),
                'process_result' => $result,
                'channel_trade_no' => (string) ($result['channel_trade_no'] ?? ''),
                'channel_order_no' => (string) ($result['channel_order_no'] ?? ''),
                'paid_at' => $result['paid_at'] ?? null,
                'failed_at' => $result['failed_at'] ?? null,
                'channel_error_code' => (string) ($result['channel_error_code'] ?? ''),
                'channel_error_msg' => (string) ($result['channel_error_msg'] ?? ''),
                // 回调原文和插件解析结果只进入 ma_pay_callback_log；
                // 支付单本身只更新状态、渠道单号和错误字段，避免 ext_json 变成通知历史桶。
                'ext_json' => [],
            ];
            // 部分渠道会返回实际手续费，补充进回调载荷，便于后续清算和对账。
            if ($result['fee_actual_amount'] !== null) {
                $callbackPayload['fee_actual_amount'] = (int) $result['fee_actual_amount'];
            }
            if ($status === PaymentPluginStatusConstant::PENDING) {
                // 渠道通知已通过验签但尚未终态时，只记录日志，不提前推进支付单状态。
                $this->notifyService->recordPayCallback($callbackPayload);
                return $plugin->notifySuccess();
            }

            // 回调终态统一交给生命周期服务落库，避免状态推进分散在不同分支里。
            $this->handleChannelCallback($callbackPayload);

            // 只要验签通过且已被系统处理，统一回成功响应，避免渠道对失败终态反复重推。
            return $plugin->notifySuccess();
        } catch (PaymentException $e) {
            // 验签失败或插件解析失败时，记录失败日志并返回失败响应，允许渠道按自身策略重推。
            $this->notifyService->recordPayCallback([
                'pay_no' => $payNo,
                'channel_id' => (int) $payOrder->channel_id,
                'callback_type' => NotifyConstant::CALLBACK_TYPE_ASYNC,
                'request_data' => array_merge($request->get(), $request->post()),
                'verify_status' => NotifyConstant::VERIFY_STATUS_FAILED,
                'process_status' => NotifyConstant::PROCESS_STATUS_FAILED,
                'process_result' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                ],
            ]);

            return $plugin->notifyFail();
        } catch (\Throwable $e) {
            // 非业务异常同样记为失败，避免渠道重复推送造成状态抖动。
            $this->notifyService->recordPayCallback([
                'pay_no' => $payNo,
                'channel_id' => (int) $payOrder->channel_id,
                'callback_type' => NotifyConstant::CALLBACK_TYPE_ASYNC,
                'request_data' => array_merge($request->get(), $request->post()),
                'verify_status' => NotifyConstant::VERIFY_STATUS_FAILED,
                'process_status' => NotifyConstant::PROCESS_STATUS_FAILED,
                'process_result' => [
                    'message' => $e->getMessage(),
                    'code' => 'PLUGIN_NOTIFY_ERROR',
                ],
            ]);

            return $plugin->notifyFail();
        }
    }

    /**
     * 校验插件回调结果。
     *
     * 插件 `notify()` 必须直接返回当前系统约定的标准字段；
     * 服务层不再做字段别名兼容或自动补齐。
     *
     * @param array<string, mixed> $result 插件返回值
     * @return array<string, mixed>
     * @throws PaymentException
     */
    private function validatePluginNotifyResult(array $result): array
    {
        $requiredKeys = [
            'status',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $result)) {
                throw new PaymentException('插件回调返回缺少标准字段', 40200, [
                    'missing_key' => $key,
                ]);
            }
        }

        $status = strtolower(trim((string) $result['status']));
        if (!in_array($status, PaymentPluginStatusConstant::notifyStatuses(), true)) {
            throw new PaymentException('插件回调返回的状态不合法', 40200, [
                'status' => $status,
            ]);
        }

        $channelOrderNo = trim((string) ($result['channel_order_no'] ?? ''));
        $channelTradeNo = trim((string) ($result['channel_trade_no'] ?? ''));
        if ($channelOrderNo === '' && $channelTradeNo === '') {
            throw new PaymentException('插件回调必须返回 channel_order_no 或 channel_trade_no', 40200);
        }
        if ($channelOrderNo === '') {
            $channelOrderNo = $channelTradeNo;
        }
        if ($channelTradeNo === '') {
            $channelTradeNo = $channelOrderNo;
        }

        if (array_key_exists('ext_json', $result) && !is_array($result['ext_json'])) {
            throw new PaymentException('插件回调 ext_json 必须为数组', 40200);
        }

        $feeActualAmount = null;
        if (array_key_exists('fee_actual_amount', $result) && $result['fee_actual_amount'] !== null) {
            if (!is_numeric($result['fee_actual_amount'])) {
                throw new PaymentException('插件回调 fee_actual_amount 必须为数字', 40200);
            }
            $feeActualAmount = (int) $result['fee_actual_amount'];
        }

        return [
            'status' => $status,
            'message' => trim((string) ($result['message'] ?? '')),
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelTradeNo,
            'channel_status' => trim((string) ($result['channel_status'] ?? '')),
            'channel_error_code' => trim((string) ($result['channel_error_code'] ?? '')),
            'channel_error_msg' => trim((string) ($result['channel_error_msg'] ?? '')),
            'paid_at' => $result['paid_at'] ?? null,
            'failed_at' => $result['failed_at'] ?? null,
            'fee_actual_amount' => $feeActualAmount,
            'ext_json' => (array) ($result['ext_json'] ?? []),
        ];
    }

    /**
     * 根据插件标准状态映射日志处理状态。
     *
     * @param string $status 标准状态
     * @return int
     */
    private function resolveProcessStatus(string $status): int
    {
        return match ($status) {
            PaymentPluginStatusConstant::SUCCESS => NotifyConstant::PROCESS_STATUS_SUCCESS,
            PaymentPluginStatusConstant::FAILED => NotifyConstant::PROCESS_STATUS_FAILED,
            default => NotifyConstant::PROCESS_STATUS_PENDING,
        };
    }
}
