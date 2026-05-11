<?php

namespace app\listener;

use app\model\payment\PayOrder;
use app\service\payment\receipt\ReceiptWatcherService;
use support\Log;

/**
 * 网页流水监听事件监听器。
 */
class ReceiptWatcherListener
{
    /**
     * 构造方法。
     *
     * @param ReceiptWatcherService $receiptWatcherService 网页流水监听服务
     */
    public function __construct(
        protected ReceiptWatcherService $receiptWatcherService
    ) {
    }

    /**
     * 配置变更后刷新账号缓存。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onConfigChanged(array $payload = [], string $eventName = ''): void
    {
        try {
            $this->receiptWatcherService->refreshChannelCache();
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[ReceiptWatcherListener] 刷新网页流水监听缓存失败 event=%s error=%s',
                $eventName,
                $e->getMessage()
            ));
        }
    }

    /**
     * 支付单进入终态后清理账号查询任务。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onPayOrderTerminated(array $payload = [], string $eventName = ''): void
    {
        try {
            $payOrder = $payload['pay_order'] ?? null;
            if (!$payOrder instanceof PayOrder) {
                return;
            }

            $this->receiptWatcherService->cleanupPayOrder($payOrder);
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[ReceiptWatcherListener] 清理网页流水监听任务失败 event=%s pay_no=%s error=%s',
                $eventName,
                (string) ($payload['pay_no'] ?? ''),
                $e->getMessage()
            ));
        }
    }
}
