<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\PaymentQueueConstant;
use Webman\RedisQueue\Redis;

/**
 * 支付域 Redis 队列投递服务。
 *
 * 统一封装投递参数，避免业务服务直接依赖 webman/redis-queue
 * 的消息结构，后续更换队列实现时只需要调整本服务。
 */
class PaymentQueueService extends BaseService
{
    /**
     * 投递商户通知任务。
     *
     * @param string $notifyNo 通知任务号
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function sendMerchantNotify(string $notifyNo, int $delay = 0): bool
    {
        return $this->send(PaymentQueueConstant::MERCHANT_NOTIFY, [
            'notify_no' => $notifyNo,
        ], $delay);
    }

    /**
     * 投递退款通道请求任务。
     *
     * @param string $refundNo 退款单号
     * @param bool $isRetry 是否为重试派发
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function sendRefundDispatch(string $refundNo, bool $isRetry = false, int $delay = 0): bool
    {
        return $this->send(PaymentQueueConstant::REFUND_DISPATCH, [
            'refund_no' => $refundNo,
            'is_retry' => $isRetry,
        ], $delay);
    }

    /**
     * 投递转账通道派发任务。
     *
     * @param string $bizNo 转账单号
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function sendTransferDispatch(string $bizNo, int $delay = 0): bool
    {
        return $this->send(PaymentQueueConstant::TRANSFER_DISPATCH, [
            'biz_no' => $bizNo,
        ], $delay);
    }

    /**
     * 投递转账查单任务。
     *
     * @param string $bizNo 转账单号
     * @param int $attempt 当前查单次数
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function sendTransferQuery(string $bizNo, int $attempt = 0, int $delay = 0): bool
    {
        return $this->send(PaymentQueueConstant::TRANSFER_QUERY, [
            'biz_no' => $bizNo,
            'attempt' => max(0, $attempt),
        ], $delay);
    }

    /**
     * 投递清算自动入账任务。
     *
     * @param string $settleNo 清算单号
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function sendSettlementComplete(string $settleNo, int $delay = 0): bool
    {
        return $this->send(PaymentQueueConstant::SETTLEMENT_COMPLETE, [
            'settle_no' => $settleNo,
        ], $delay);
    }

    /**
     * 投递网页流水监听通知任务。
     *
     * @param array<string, mixed> $payload 已归一化的流水载荷
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function sendReceiptFlowNotify(array $payload, int $delay = 0): bool
    {
        return $this->send(PaymentQueueConstant::RECEIPT_FLOW_NOTIFY, $payload, $delay);
    }

    /**
     * 投递原始队列消息。
     *
     * @param string $queue 队列名
     * @param array<string, mixed> $data 载荷
     * @param int $delay 延迟秒数
     * @return bool 是否投递成功
     */
    public function send(string $queue, array $data, int $delay = 0): bool
    {
        return Redis::send($queue, $data, max(0, $delay));
    }
}
