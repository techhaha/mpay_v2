<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\BadRequestException;
use app\models\PaymentOrder;
use app\repositories\PaymentOrderRepository;

/**
 * 支付订单状态机服务（最小版）
 *
 * 约束核心状态迁移：
 * - PENDING -> SUCCESS/FAIL/CLOSED
 * - SUCCESS -> CLOSED
 */
class PaymentStateService extends BaseService
{
    public function __construct(
        protected PaymentOrderRepository $orderRepository
    ) {
    }

    /**
     * 回调支付成功。
     *
     * @return bool true=状态有变更, false=幂等无变更
     */
    public function markPaid(PaymentOrder $order, string $chanTradeNo = '', ?string $payAt = null): bool
    {
        $from = (int)$order->status;
        if ($from === PaymentOrder::STATUS_SUCCESS) {
            return false;
        }
        if (!$this->canTransit($from, PaymentOrder::STATUS_SUCCESS)) {
            throw new BadRequestException("illegal status transition: {$from} -> " . PaymentOrder::STATUS_SUCCESS);
        }

        $ok = $this->orderRepository->updateById((int)$order->id, [
            'status' => PaymentOrder::STATUS_SUCCESS,
            'pay_at' => $payAt ?: date('Y-m-d H:i:s'),
            'chan_trade_no' => $chanTradeNo !== '' ? $chanTradeNo : (string)$order->chan_trade_no,
        ]);

        return (bool)$ok;
    }

    /**
     * 标记支付失败（用于已验签的失败回调）。
     *
     * @return bool true=状态有变更, false=幂等无变更
     */
    public function markFailed(PaymentOrder $order): bool
    {
        $from = (int)$order->status;
        if ($from === PaymentOrder::STATUS_FAIL) {
            return false;
        }
        if (!$this->canTransit($from, PaymentOrder::STATUS_FAIL)) {
            throw new BadRequestException("illegal status transition: {$from} -> " . PaymentOrder::STATUS_FAIL);
        }

        $ok = $this->orderRepository->updateById((int)$order->id, [
            'status' => PaymentOrder::STATUS_FAIL,
        ]);

        return (bool)$ok;
    }

    /**
     * 全额退款后关单。
     *
     * @return bool true=状态有变更, false=幂等无变更
     */
    public function closeAfterFullRefund(PaymentOrder $order, array $refundInfo = []): bool
    {
        $from = (int)$order->status;
        if ($from === PaymentOrder::STATUS_CLOSED) {
            return false;
        }
        if (!$this->canTransit($from, PaymentOrder::STATUS_CLOSED)) {
            throw new BadRequestException("illegal status transition: {$from} -> " . PaymentOrder::STATUS_CLOSED);
        }

        $extra = $order->extra ?? [];
        $extra['refund_info'] = $refundInfo;

        $ok = $this->orderRepository->updateById((int)$order->id, [
            'status' => PaymentOrder::STATUS_CLOSED,
            'extra' => $extra,
        ]);

        return (bool)$ok;
    }

    private function canTransit(int $from, int $to): bool
    {
        $allowed = [
            PaymentOrder::STATUS_PENDING => [
                PaymentOrder::STATUS_SUCCESS,
                PaymentOrder::STATUS_FAIL,
                PaymentOrder::STATUS_CLOSED,
            ],
            PaymentOrder::STATUS_SUCCESS => [
                PaymentOrder::STATUS_CLOSED,
            ],
            PaymentOrder::STATUS_FAIL => [],
            PaymentOrder::STATUS_CLOSED => [],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }
}
