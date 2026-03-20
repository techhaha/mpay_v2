<?php

namespace app\services;

use app\common\base\BaseService;
use app\repositories\CallbackInboxRepository;
use app\repositories\PaymentChannelRepository;
use app\repositories\PaymentCallbackLogRepository;
use app\repositories\PaymentOrderRepository;
use support\Request;

/**
 * 支付回调处理服务
 *
 * 流程：验签 -> 幂等 -> 更新订单 -> 创建商户通知任务。
 */
class PayNotifyService extends BaseService
{
    public function __construct(
        protected PluginService $pluginService,
        protected PaymentStateService $paymentStateService,
        protected CallbackInboxRepository $callbackInboxRepository,
        protected PaymentChannelRepository $channelRepository,
        protected PaymentCallbackLogRepository $callbackLogRepository,
        protected PaymentOrderRepository $orderRepository,
        protected NotifyService $notifyService,
    ) {
    }

    /**
     * @return array{ok:bool,already?:bool,msg:string,order_id?:string}
     */
    public function handleNotify(string $pluginCode, Request $request): array
    {
        $rawPayload = array_merge($request->get(), $request->post());
        $candidateOrderId = $this->extractOrderIdFromPayload($rawPayload);
        $order = $candidateOrderId !== '' ? $this->orderRepository->findByOrderId($candidateOrderId) : null;

        try {
            $plugin = $this->pluginService->getPluginInstance($pluginCode);

            // 验签前初始化插件配置，保证如支付宝证书验签等能力可用。
            if ($order && (int)$order->channel_id > 0) {
                $channel = $this->channelRepository->find((int)$order->channel_id);
                if ($channel) {
                    if ((string)$channel->plugin_code !== $pluginCode) {
                        return ['ok' => false, 'msg' => 'plugin mismatch'];
                    }
                    $channelConfig = array_merge(
                        $channel->getConfigArray(),
                        ['enabled_products' => $channel->getEnabledProducts()]
                    );
                    $plugin->init($channelConfig);
                }
            }

            $notifyData = $plugin->notify($request);
        } catch (\Throwable $e) {
            $this->callbackLogRepository->createLog([
                'order_id' => $candidateOrderId,
                'channel_id' => $order ? (int)$order->channel_id : 0,
                'callback_type' => 'notify',
                'request_data' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE),
                'verify_status' => 0,
                'process_status' => 0,
                'process_result' => $e->getMessage(),
            ]);
            return ['ok' => false, 'msg' => 'verify failed'];
        }

        $orderId = (string)($notifyData['pay_order_id'] ?? '');
        $status = strtolower((string)($notifyData['status'] ?? ''));
        $chanTradeNo = (string)($notifyData['chan_trade_no'] ?? '');

        if ($orderId === '') {
            return ['ok' => false, 'msg' => 'missing pay_order_id'];
        }

        // 已验签但状态非 success 时，也走状态机进行失败态收敛。
        if ($status !== 'success') {
            $order = $this->orderRepository->findByOrderId($orderId);
            if ($order) {
                try {
                    $this->paymentStateService->markFailed($order);
                } catch (\Throwable $e) {
                    // 非法迁移不影响回调日志记录
                }
            }

            $this->callbackLogRepository->createLog([
                'order_id' => $orderId,
                'channel_id' => $order ? (int)$order->channel_id : 0,
                'callback_type' => 'notify',
                'request_data' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE),
                'verify_status' => 1,
                'process_status' => 0,
                'process_result' => 'notify status is not success',
            ]);

            return ['ok' => false, 'msg' => 'notify status is not success'];
        }

        $eventKey = $this->buildEventKey($pluginCode, $orderId, $chanTradeNo, $notifyData);
        $payload = $rawPayload;

        $inserted = $this->callbackInboxRepository->createIfAbsent([
            'event_key' => $eventKey,
            'plugin_code' => $pluginCode,
            'order_id' => $orderId,
            'chan_trade_no' => $chanTradeNo,
            'payload' => $payload,
            'process_status' => 0,
            'processed_at' => null,
        ]);

        if (!$inserted) {
            return ['ok' => true, 'already' => true, 'msg' => 'success', 'order_id' => $orderId];
        }

        $order = $this->orderRepository->findByOrderId($orderId);
        if (!$order) {
            $this->callbackLogRepository->createLog([
                'order_id' => $orderId,
                'channel_id' => 0,
                'callback_type' => 'notify',
                'request_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'verify_status' => 1,
                'process_status' => 0,
                'process_result' => 'order not found',
            ]);

            return ['ok' => false, 'msg' => 'order not found'];
        }

        try {
            $this->transaction(function () use ($order, $chanTradeNo, $payload, $pluginCode) {
                $this->paymentStateService->markPaid($order, $chanTradeNo);

                $this->callbackLogRepository->createLog([
                    'order_id' => $order->order_id,
                    'channel_id' => (int)$order->channel_id,
                    'callback_type' => 'notify',
                    'request_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'verify_status' => 1,
                    'process_status' => 1,
                    'process_result' => 'success:' . $pluginCode,
                ]);

                $this->notifyService->createNotifyTask($order->order_id);
            });
        } catch (\Throwable $e) {
            $this->callbackLogRepository->createLog([
                'order_id' => $order->order_id,
                'channel_id' => (int)$order->channel_id,
                'callback_type' => 'notify',
                'request_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'verify_status' => 1,
                'process_status' => 0,
                'process_result' => $e->getMessage(),
            ]);
            return ['ok' => false, 'msg' => 'process failed'];
        }

        $event = $this->callbackInboxRepository->findByEventKey($eventKey);
        if ($event) {
            $this->callbackInboxRepository->updateById((int)$event->id, [
                'process_status' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['ok' => true, 'msg' => 'success', 'order_id' => $orderId];
    }

    private function buildEventKey(string $pluginCode, string $orderId, string $chanTradeNo, array $notifyData): string
    {
        $base = $pluginCode . '|' . $orderId . '|' . $chanTradeNo . '|' . ($notifyData['status'] ?? '');
        return sha1($base);
    }

    private function extractOrderIdFromPayload(array $payload): string
    {
        $candidates = [
            $payload['pay_order_id'] ?? null,
            $payload['order_id'] ?? null,
            $payload['out_trade_no'] ?? null,
            $payload['trade_no'] ?? null,
        ];

        foreach ($candidates as $id) {
            $value = trim((string)$id);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
