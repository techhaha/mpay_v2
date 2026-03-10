<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentOrder;

/**
 * 支付订单仓储
 */
class PaymentOrderRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentOrder());
    }

    public function findByOrderId(string $orderId): ?PaymentOrder
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->first();
    }

    /**
     * 根据商户订单号查询（幂等校验）
     */
    public function findByMchNo(int $merchantId, int $merchantAppId, string $mchOrderNo): ?PaymentOrder
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_app_id', $merchantAppId)
            ->where('mch_order_no', $mchOrderNo)
            ->first();
    }

    public function updateStatus(string $orderId, int $status, array $extra = []): bool
    {
        $data = array_merge(['status' => $status], $extra);
        $order = $this->findByOrderId($orderId);
        return $order ? $this->updateById($order->id, $data) : false;
    }

    public function updateChannelInfo(string $orderId, string $chanOrderNo, string $chanTradeNo = ''): bool
    {
        $order = $this->findByOrderId($orderId);
        if (!$order) {
            return false;
        }
        $data = ['chan_order_no' => $chanOrderNo];
        if ($chanTradeNo !== '') {
            $data['chan_trade_no'] = $chanTradeNo;
        }
        return $this->updateById($order->id, $data);
    }
}
