<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\RefundOrder;

/**
 * 退款单仓库。
 */
class RefundOrderRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new RefundOrder());
    }

    /**
     * 根据退款单号查询退款单。
     */
    public function findByRefundNo(string $refundNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('refund_no', $refundNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询退款单。
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询退款单列表。
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
     */
    public function findByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->first($columns);
    }

    /**
     * 根据退款单号加锁查询退款单。
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
     */
    public function findForUpdateByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 统计商户下的退款订单数量。
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}

