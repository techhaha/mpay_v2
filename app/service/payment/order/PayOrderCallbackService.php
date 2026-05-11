<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\ChannelNotifyInterface;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\NotifyService;
use app\service\payment\runtime\PaymentPluginManager;
use support\Request;
use support\Response;
use Throwable;

/**
 * 支付单回调服务。
 *
 * 负责渠道回调日志记录、插件回调解析和支付状态分发。
 *
 * @property NotifyService $notifyService 通知服务
 * @property PaymentPluginManager $paymentPluginManager 支付插件管理器
 * @property PaymentChannelRepository $paymentChannelRepository 支付通道仓库
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
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
     */
    public function __construct(
        protected NotifyService $notifyService,
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService
    ) {
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
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $plugin = null;
        $callbackPayload = null;
        try {
            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $notifyResult = PaymentPluginNotifyResultValidator::make($plugin->notify($request))
                ->withScene('notify_result')
                ->withException(PaymentException::class)
                ->validate();
            $notifyPayNo = trim((string) ($notifyResult['pay_no'] ?? ''));
            if ($notifyPayNo !== '' && $notifyPayNo !== (string) $payOrder->pay_no) {
                throw new PaymentException('插件回调定位的支付单与当前支付单不一致', 40200, [
                    'callback_pay_no' => (string) $payOrder->pay_no,
                    'notify_pay_no' => $notifyPayNo,
                ]);
            }
            $callbackPayload = $this->buildCallbackPayload($payOrder, $request->all(), $notifyResult);
            $this->applyNotifyResult($payOrder, $notifyResult, $callbackPayload);

            return $plugin->notifySuccess();
        } catch (PaymentException $e) {
            $exception = (int) $e->getCode() === 400
                ? new PaymentException($e->getMessage(), 40200, $e->getData())
                : $e;
            $this->recordCallbackFailure($payOrder, $request->all(), $exception, $callbackPayload);

            return $plugin ? $plugin->notifyFail() : 'fail';
        } catch (Throwable $e) {
            $this->recordCallbackFailure($payOrder, $request->all(), $e, $callbackPayload);

            return $plugin ? $plugin->notifyFail() : 'fail';
        }
    }

    /**
     * 按通道处理不携带支付单号的 HTTP 通知。
     *
     * 服务层只让插件定位 pay_no，定位成功后继续走标准插件 notify() 回调流程。
     *
     * @param int $channelId 通道ID
     * @param Request $request 请求对象
     * @return string|Response 字符串或响应对象
     * @throws ResourceNotFoundException
     */
    public function handleChannelNotify(int $channelId, Request $request): string|Response
    {
        $channel = $this->paymentChannelRepository->find($channelId);
        if (!$channel) {
            throw new ResourceNotFoundException('支付通道不存在', ['channel_id' => $channelId]);
        }

        $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $channel->pay_type_id, true);
        if (!$plugin instanceof ChannelNotifyInterface) {
            throw new PaymentException('当前通道不支持通道通知入口', 40200, [
                'channel_id' => $channelId,
                'plugin_code' => (string) $channel->plugin_code,
            ]);
        }

        try {
            $result = $plugin->channelNotify($request);
            $payNo = trim((string) ($result['pay_no'] ?? ''));
            if ($payNo === '') {
                throw new PaymentException('通道通知未定位到支付单', 40200, [
                    'channel_id' => $channelId,
                    'result' => $result,
                ]);
            }

            return $this->handlePluginCallback($payNo, $request);
        } catch (Throwable $e) {
            return $plugin->notifyFail();
        }
    }

    /**
     * 按通道处理 Redis 队列投递的归一化流水载荷。
     *
     * 该入口不依赖 Request。插件先通过数组载荷定位 pay_no，再通过 notifyPayload()
     * 返回标准通知结果，服务层复用订单状态推进、回调日志和商户通知链路。
     *
     * @param int $channelId 通道ID
     * @param array<string, mixed> $payload 已归一化的流水载荷
     * @return array<string, mixed> 渠道回调日志载荷
     */
    public function handleChannelNotifyPayload(int $channelId, array $payload): array
    {
        $channel = $this->paymentChannelRepository->find($channelId);
        if (!$channel) {
            throw new ResourceNotFoundException('支付通道不存在', ['channel_id' => $channelId]);
        }

        $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $channel->pay_type_id, true);
        if (!$plugin instanceof ChannelNotifyPayloadInterface) {
            throw new PaymentException('当前通道不支持数组载荷通知入口', 40200, [
                'channel_id' => $channelId,
                'plugin_code' => (string) $channel->plugin_code,
            ]);
        }

        $result = $plugin->channelNotifyPayload($payload);
        $payNo = trim((string) ($result['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('通道通知未定位到支付单', 40200, [
                'channel_id' => $channelId,
                'result' => $result,
            ]);
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $callbackPayload = null;
        try {
            $notifyResult = PaymentPluginNotifyResultValidator::make($plugin->notifyPayload($payload))
                ->withScene('notify_result')
                ->withException(PaymentException::class)
                ->validate();
            $notifyPayNo = trim((string) ($notifyResult['pay_no'] ?? ''));
            if ($notifyPayNo !== '' && $notifyPayNo !== (string) $payOrder->pay_no) {
                throw new PaymentException('插件回调定位的支付单与当前支付单不一致', 40200, [
                    'callback_pay_no' => (string) $payOrder->pay_no,
                    'notify_pay_no' => $notifyPayNo,
                ]);
            }

            $callbackPayload = $this->buildCallbackPayload($payOrder, $payload, $notifyResult);
            $this->applyNotifyResult($payOrder, $notifyResult, $callbackPayload);

            return $callbackPayload;
        } catch (PaymentException $e) {
            $exception = (int) $e->getCode() === 400
                ? new PaymentException($e->getMessage(), 40200, $e->getData())
                : $e;
            $this->recordCallbackFailure($payOrder, $payload, $exception, $callbackPayload);
            throw $exception;
        } catch (Throwable $e) {
            $this->recordCallbackFailure($payOrder, $payload, $e, $callbackPayload);
            throw $e;
        }
    }

    /**
     * 构建生命周期服务可消费的回调载荷。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $requestData 请求或队列载荷
     * @param array<string, mixed> $notifyResult 插件回调结果
     * @return array<string, mixed>
     */
    private function buildCallbackPayload(PayOrder $payOrder, array $requestData, array $notifyResult): array
    {
        $status = (string) $notifyResult['status'];
        $payload = [
            'pay_no' => (string) $payOrder->pay_no,
            'success' => $status === PaymentPluginStatusConstant::SUCCESS,
            'channel_id' => (int) $payOrder->channel_id,
            'callback_type' => NotifyConstant::CALLBACK_TYPE_ASYNC,
            'request_data' => $requestData,
            'verify_status' => NotifyConstant::VERIFY_STATUS_SUCCESS,
            'process_status' => match ($status) {
                PaymentPluginStatusConstant::SUCCESS => NotifyConstant::PROCESS_STATUS_SUCCESS,
                PaymentPluginStatusConstant::FAILED => NotifyConstant::PROCESS_STATUS_FAILED,
                default => NotifyConstant::PROCESS_STATUS_PENDING,
            },
            'process_result' => $notifyResult,
            'channel_order_no' => (string) $notifyResult['channel_order_no'],
            'channel_trade_no' => (string) $notifyResult['channel_trade_no'],
        ];

        foreach (['paid_at', 'failed_at', 'channel_error_code', 'channel_error_msg'] as $key) {
            if (($notifyResult[$key] ?? null) !== null && $notifyResult[$key] !== '') {
                $payload[$key] = $notifyResult[$key];
            }
        }

        return $payload;
    }

    /**
     * 按插件通知结果推进支付单并记录回调日志。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $notifyResult 插件回调结果
     * @param array<string, mixed> $callbackPayload 渠道回调日志载荷
     * @return void
     */
    private function applyNotifyResult(PayOrder $payOrder, array $notifyResult, array $callbackPayload): void
    {
        $status = (string) $notifyResult['status'];
        if ($status === PaymentPluginStatusConstant::PENDING) {
            $this->notifyService->recordPayCallback($callbackPayload);
            return;
        }

        if ($status === PaymentPluginStatusConstant::SUCCESS) {
            $this->payOrderLifecycleService->markPaySuccess((string) $payOrder->pay_no, $callbackPayload);
        } else {
            $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, $callbackPayload);
        }

        $this->notifyService->recordPayCallback($callbackPayload);
    }

    /**
     * 记录回调处理失败。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $requestData 请求或队列载荷
     * @param Throwable $e 异常
     * @param array<string, mixed>|null $callbackPayload 已通过插件解析的回调载荷
     * @return void
     */
    private function recordCallbackFailure(PayOrder $payOrder, array $requestData, Throwable $e, ?array $callbackPayload = null): void
    {
        $exceptionResult = [
            'message' => $e->getMessage(),
            'code' => $e instanceof PaymentException ? $e->getCode() : 'PLUGIN_NOTIFY_ERROR',
        ];

        if ($callbackPayload !== null) {
            $this->notifyService->recordPayCallback(array_replace($callbackPayload, [
                'verify_status' => NotifyConstant::VERIFY_STATUS_SUCCESS,
                'process_status' => NotifyConstant::PROCESS_STATUS_FAILED,
                'process_result' => [
                    'notify_result' => $callbackPayload['process_result'] ?? [],
                    'exception' => $exceptionResult,
                ],
            ]));
            return;
        }

        $this->notifyService->recordPayCallback([
            'pay_no' => (string) $payOrder->pay_no,
            'channel_id' => (int) $payOrder->channel_id,
            'callback_type' => NotifyConstant::CALLBACK_TYPE_ASYNC,
            'request_data' => $requestData,
            'verify_status' => NotifyConstant::VERIFY_STATUS_FAILED,
            'process_status' => NotifyConstant::PROCESS_STATUS_FAILED,
            'process_result' => $exceptionResult,
        ]);
    }
}
