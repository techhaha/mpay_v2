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
     * @throws \InvalidArgumentException
     */
    public function handleChannelCallback(array $input): PayOrder
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new \InvalidArgumentException('pay_no 不能为空');
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
            // 由插件自行解析请求并返回统一结构，控制器层不直接判断渠道格式。
            $result = $plugin->notify($request);
            $status = (string) ($result['status'] ?? '');
            // 老插件可能只返回 success / paid / failed 这类状态字符串，这里统一折算成布尔结果。
            $success = array_key_exists('success', $result)
                ? (bool) $result['success']
                : in_array($status, ['success', 'paid'], true);

            // 将插件返回值归一化为生命周期服务可消费的回调载荷。
            /** @var array<string, mixed> $callbackPayload */
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
            // 部分渠道会返回实际手续费，补充进回调载荷，便于后续清算和对账。
            if (isset($result['fee_actual_amount'])) {
                $callbackPayload['fee_actual_amount'] = (int) $result['fee_actual_amount'];
            }

            // 回调成功后统一交给生命周期服务落库，避免状态推进分散在不同分支里。
            $this->handleChannelCallback($callbackPayload);

            return $success ? $plugin->notifySuccess() : $plugin->notifyFail();
        } catch (PaymentException $e) {
            // 插件已明确返回业务失败时，记录失败日志并按失败响应收口。
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
}
