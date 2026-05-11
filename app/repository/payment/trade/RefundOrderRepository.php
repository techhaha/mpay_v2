<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\RefundOrder;

/**
 * 退款单基础查询仓库。
 *
 * 封装退款单号、业务单号、追踪号、支付单号和商户退款号等常用查询方法。
 */
class RefundOrderRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new RefundOrder());
    }

    /**
     * 根据退款单号查询退款单。
     *
     * @param string $refundNo 退款单号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findByRefundNo(string $refundNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('refund_no', $refundNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询退款单。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询退款单列表。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, RefundOrder> 退款单列表
     */
    public function listByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据业务单号查询退款单列表。
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, RefundOrder> 退款单列表
     */
    public function listByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据商户退款单号查询退款单。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantRefundNo 商户退款号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findByMerchantRefundNo(int $merchantId, string $merchantRefundNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_refund_no', $merchantRefundNo)
            ->first($columns);
    }

    /**
     * 根据支付单号查询退款单。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->first($columns);
    }

    /**
     * 根据退款单号加锁查询退款单。
     *
     * @param string $refundNo 退款单号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findForUpdateByRefundNo(string $refundNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('refund_no', $refundNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据追踪号加锁查询退款单。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findForUpdateByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据支付单号加锁查询退款单。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return RefundOrder|null 退款单记录
     */
    public function findForUpdateByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 锁定指定支付单下会占用可退余额的退款单。
     *
     * @param string $payNo 支付单号
     * @param array<int, int> $statuses 状态列表
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, RefundOrder> 退款单列表
     */
    public function listForUpdateByPayNoAndStatuses(string $payNo, array $statuses, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->whereIn('status', $statuses)
            ->lockForUpdate()
            ->get($columns);
    }

    /**
     * 统计商户下的退款订单数量。
     *
     * @param int $merchantId 商户ID
     * @return int 退款订单数量
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}


