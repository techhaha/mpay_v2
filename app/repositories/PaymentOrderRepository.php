<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentOrder;

/**
 * 支付订单仓储
 */
class PaymentOrderRepository extends BaseRepository
{
    public const STATUS_PENDING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAIL = 2;
    public const STATUS_CLOSED = 3;

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
        $query = $this->buildSearchQuery($filters);
        $query->orderByDesc('id');
        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function searchList(array $filters = [], int $limit = 5000)
    {
        return $this->buildSearchQuery($filters)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function aggregateByChannel(array $channelIds = [], array $filters = []): array
    {
        if (empty($channelIds)) {
            return [];
        }

        $query = $this->model->newQuery()
            ->selectRaw(
                'channel_id,
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS success_orders,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_orders,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS fail_orders,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS closed_orders,
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS success_amount,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) AS today_amount,
                SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = ? THEN 1 ELSE 0 END) AS today_success_orders,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = ? THEN amount ELSE 0 END), 0) AS today_success_amount,
                MAX(created_at) AS last_order_at,
                MAX(CASE WHEN status = ? THEN pay_at ELSE NULL END) AS last_success_at',
                [
                    self::STATUS_SUCCESS,
                    self::STATUS_PENDING,
                    self::STATUS_FAIL,
                    self::STATUS_CLOSED,
                    self::STATUS_SUCCESS,
                    self::STATUS_SUCCESS,
                    self::STATUS_SUCCESS,
                    self::STATUS_SUCCESS,
                ]
            )
            ->whereIn('channel_id', $channelIds);

        if (!empty($filters['merchant_id'])) {
            $query->where('merchant_id', (int)$filters['merchant_id']);
        }
        if (!empty($filters['merchant_app_id'])) {
            $query->where('merchant_app_id', (int)$filters['merchant_app_id']);
        }
        if (!empty($filters['method_id'])) {
            $query->where('method_id', (int)$filters['method_id']);
        }
        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        $rows = $query->groupBy('channel_id')->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->channel_id] = $row->toArray();
        }

        return $result;
    }

    private function buildSearchQuery(array $filters = [])
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
        if (!empty($filters['route_policy_name'])) {
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(extra, '$.routing.policy.policy_name')) like ?",
                ['%' . $filters['route_policy_name'] . '%']
            );
        }
        if (($filters['route_state'] ?? '') !== '' && $filters['route_state'] !== null) {
            $routeState = (string)$filters['route_state'];
            if ($routeState === 'error') {
                $query->whereRaw("JSON_EXTRACT(extra, '$.route_error') IS NOT NULL");
            } elseif ($routeState === 'none') {
                $query->whereRaw("JSON_EXTRACT(extra, '$.route_error') IS NULL");
                $query->whereRaw(
                    "(JSON_UNQUOTE(JSON_EXTRACT(extra, '$.routing.source')) IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(extra, '$.routing.source')) = '')"
                );
            } else {
                $query->whereRaw(
                    "JSON_EXTRACT(extra, '$.route_error') IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(extra, '$.routing.source')) = ?",
                    [$routeState]
                );
            }
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

        return $query;
    }
}
