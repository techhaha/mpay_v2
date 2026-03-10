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

    /**
     * 后台订单列表：支持筛选与模糊搜索
     */
    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->model->newQuery();

        if (!empty($filters['merchant_id'])) {
            $query->where('merchant_id', (int)$filters['merchant_id']);
        }
        if (!empty($filters['merchant_app_id'])) {
            $query->where('merchant_app_id', (int)$filters['merchant_app_id']);
        }
        if (!empty($filters['method_id'])) {
            $query->where('method_id', (int)$filters['method_id']);
        }
        if (!empty($filters['channel_id'])) {
            $query->where('channel_id', (int)$filters['channel_id']);
        }
        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['order_id'])) {
            $query->where('order_id', 'like', '%' . $filters['order_id'] . '%');
        }
        if (!empty($filters['mch_order_no'])) {
            $query->where('mch_order_no', 'like', '%' . $filters['mch_order_no'] . '%');
        }
        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        $query->orderByDesc('id');
        return $query->paginate($pageSize, ['*'], 'page', $page);
    }
}
