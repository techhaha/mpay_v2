<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
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
 */
class PayOrderCallbackService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected NotifyService $notifyService,
        protected PaymentPluginManager $paymentPluginManager,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService
    ) {
    }

    /**
     * 处理渠道回调。
     */
    public function handleChannelCallback(array $input): PayOrder
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new \InvalidArgumentException('pay_no 不能为空');
        }

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
        if ($success) {
            return $this->payOrderLifecycleService->markPaySuccess($payNo, $input);
        }

        return $this->payOrderLifecycleService->markPayFailed($payNo, $input);
    }

    /**
     * 按支付单号处理真实第三方回调。
     */
    public function handlePluginCallback(string $payNo, Request $request): string|Response
    {
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);

        try {
            $result = $plugin->notify($request);
            $status = (string) ($result['status'] ?? '');
            $success = array_key_exists('success', $result)
                ? (bool) $result['success']
                : in_array($status, ['success', 'paid'], true);

            $callbackPayload = [
                'pay_no' => $payNo,
                'success' => $success,
                'channel_id' => (int) $payOrder->channel_id,
                'callback_type' => NotifyConstant::CALLBACK_TYPE_ASYNC,
                'request_data' => array_merge($request->get(), $request->post()),
                'verify_status' => NotifyConstant::VERIFY_STATUS_SUCCESS,
                'process_status' => $success ? NotifyConstant::PROCESS_STATUS_SUCCESS : NotifyConstant::PROCESS_STATUS_FAILED,
                'process_result' => $result,
                'channel_trade_no' => (string) ($result['chan_trade_no'] ?? ''),
                'channel_order_no' => (string) ($result['chan_order_no'] ?? ''),
                'paid_at' => $result['paid_at'] ?? null,
                'channel_error_code' => (string) ($result['channel_error_code'] ?? ''),
                'channel_error_msg' => (string) ($result['channel_error_msg'] ?? ''),
                'ext_json' => [
                    'plugin_code' => (string) $payOrder->plugin_code,
                    'notify_status' => $status,
                ],
            ];
            if (isset($result['fee_actual_amount'])) {
                $callbackPayload['fee_actual_amount'] = (int) $result['fee_actual_amount'];
            }

            $this->handleChannelCallback($callbackPayload);

            return $success ? $plugin->notifySuccess() : $plugin->notifyFail();
        } catch (PaymentException $e) {
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
}
