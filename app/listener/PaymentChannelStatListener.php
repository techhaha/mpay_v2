<?php

namespace app\listener;

use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\ops\stat\ChannelDailyStatService;
use support\Log;

/**
 * 支付通道日统计监听器。
 *
 * 监听支付和退款领域事件，按通道维度实时累加交易量、成功率和健康分。
 */
class PaymentChannelStatListener
{
    /**
     * 构造方法。
     *
     * @param ChannelDailyStatService $channelDailyStatService 通道日统计服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @return void
     */
    public function __construct(
        protected ChannelDailyStatService $channelDailyStatService,
        protected PayOrderRepository $payOrderRepository,
        protected RefundOrderRepository $refundOrderRepository
    ) {
    }

    /**
     * 记录支付成功统计。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onPayOrderSucceeded(array $payload = [], string $eventName = ''): void
    {
        $payOrder = $this->resolvePayOrder($payload);
        if (!$payOrder) {
            return;
        }

        $this->guardedRecord(fn () => $this->channelDailyStatService->recordPaySuccess($payOrder), $eventName, (string) $payOrder->pay_no);
    }

    /**
     * 记录支付失败、关闭或超时统计。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onPayOrderFailed(array $payload = [], string $eventName = ''): void
    {
        $payOrder = $this->resolvePayOrder($payload);
        if (!$payOrder) {
            return;
        }

        $this->guardedRecord(fn () => $this->channelDailyStatService->recordPayFailure($payOrder), $eventName, (string) $payOrder->pay_no);
    }

    /**
     * 记录退款成功统计。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onRefundOrderSucceeded(array $payload = [], string $eventName = ''): void
    {
        $refundOrder = $payload['refund_order'] ?? null;
        if (!$refundOrder instanceof RefundOrder) {
            $refundNo = trim((string) ($payload['refund_no'] ?? ''));
            $refundOrder = $refundNo !== '' ? $this->refundOrderRepository->findByRefundNo($refundNo) : null;
        }

        if (!$refundOrder instanceof RefundOrder) {
            return;
        }

        $this->guardedRecord(fn () => $this->channelDailyStatService->recordRefundSuccess($refundOrder), $eventName, (string) $refundOrder->refund_no);
    }

    /**
     * 从事件载荷中解析支付单。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @return PayOrder|null 支付单
     */
    private function resolvePayOrder(array $payload): ?PayOrder
    {
        $payOrder = $payload['pay_order'] ?? null;
        if ($payOrder instanceof PayOrder) {
            return $payOrder;
        }

        $payNo = trim((string) ($payload['pay_no'] ?? ''));
        return $payNo !== '' ? $this->payOrderRepository->findByPayNo($payNo) : null;
    }

    /**
     * 执行统计写入并吞掉监听器内部异常。
     *
     * 统计失败不应影响支付主链路，所以这里只记录日志，后续可通过补偿任务重算。
     *
     * @param callable $callback 统计写入回调
     * @param string $eventName 事件名称
     * @param string $refNo 关联单号
     * @return void
     */
    private function guardedRecord(callable $callback, string $eventName, string $refNo): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentChannelStatListener] 统计更新失败 event=%s ref_no=%s error=%s',
                $eventName,
                $refNo,
                $e->getMessage()
            ));
        }
    }
}
