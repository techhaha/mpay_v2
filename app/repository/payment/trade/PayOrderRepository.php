<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;

/**
 * 支付单基础查询仓库。
 *
 * 封装支付单号、业务单号、追踪号和商户请求号等常用查询方法。
 */
class PayOrderRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PayOrder());
    }

    /**
     * 根据支付单号查询支付单。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询支付单。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询支付单列表。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
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
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
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
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
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
     *
     * @param int $merchantId 商户ID
     * @param string $channelRequestNo 渠道Request号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
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
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
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
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
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
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return PayOrder|null 支付单记录
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
     * 查询已过期但还未进入终态的支付单。
     *
     * @param string $now 当前时间
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listExpiredMutable(string $now, int $limit = 100)
    {
        return $this->model->newQuery()
            ->whereIn('status', TradeConstant::orderMutableStatuses())
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', $now)
            ->orderBy('expire_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 查询需要主动查单的支付中订单。
     *
     * @param string $before 最早拉起时间
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 支付单列表
     */
    public function listPayingForActiveQuery(string $before, int $limit = 50)
    {
        return $this->model->newQuery()
            ->where('status', TradeConstant::ORDER_STATUS_PAYING)
            ->where('request_at', '<=', $before)
            ->where(function ($query) {
                $query->whereNull('expire_at')
                    ->orWhere('expire_at', '>', date('Y-m-d H:i:s'));
            })
            ->orderBy('request_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 查询商户最近支付单列表，用于总览展示。
     *
     * @param int $merchantId 商户ID
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, PayOrder> 最近支付单列表
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




