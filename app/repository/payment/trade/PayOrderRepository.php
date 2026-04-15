<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\PayOrder;

/**
 * 支付单仓库。
 */
class PayOrderRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PayOrder());
    }

    /**
     * 根据支付单号查询支付单。
     */
    public function findByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询支付单。
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询支付单列表。
     */
    public function listByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->orderByDesc('attempt_no')
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据业务单号查询支付单列表。
     */
    public function listByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('attempt_no')
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据业务单号查询最新支付单。
     */
    public function findLatestByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('attempt_no')
            ->first($columns);
    }

    /**
     * 根据商户和渠道请求号查询支付单。
     */
    public function findByChannelRequestNo(int $merchantId, string $channelRequestNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('channel_request_no', $channelRequestNo)
            ->first($columns);
    }

    /**
     * 根据支付单号加锁查询支付单。
     */
    public function findForUpdateByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据追踪号加锁查询支付单。
     */
    public function findForUpdateByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据业务单号加锁查询最新支付单。
     */
    public function findLatestForUpdateByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('attempt_no')
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 查询商户最近支付单列表，用于总览展示。
     */
    public function recentByMerchantId(int $merchantId, int $limit = 5)
    {
        return $this->model->newQuery()
            ->from('ma_pay_order as po')
            ->leftJoin('ma_payment_type as t', 'po.pay_type_id', '=', 't.id')
            ->leftJoin('ma_payment_channel as c', 'po.channel_id', '=', 'c.id')
            ->where('po.merchant_id', $merchantId)
            ->orderByDesc('po.id')
            ->limit(max(1, $limit))
            ->get([
                'po.pay_no',
                'po.pay_amount',
                'po.status',
                'po.created_at',
                't.name as pay_type_name',
                'c.name as channel_name',
            ]);
    }
}

