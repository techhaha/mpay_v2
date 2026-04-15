<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\BizOrder;

/**
 * 业务订单仓库。
 */
class BizOrderRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new BizOrder());
    }

    /**
     * 根据业务单号查询业务订单。
     */
    public function findByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询业务订单。
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据商户 ID 和商户订单号查询业务订单。
     */
    public function findByMerchantAndOrderNo(int $merchantId, string $merchantOrderNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_order_no', $merchantOrderNo)
            ->first($columns);
    }

    /**
     * 根据业务单号查询当前有效的业务订单。
     */
    public function findActiveByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->whereIn('status', [0, 1])
            ->first($columns);
    }

    /**
     * 根据业务单号加锁查询业务订单。
     */
    public function findForUpdateByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据追踪号加锁查询业务订单。
     */
    public function findForUpdateByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据商户 ID 和商户订单号加锁查询业务订单。
     */
    public function findForUpdateByMerchantAndOrderNo(int $merchantId, string $merchantOrderNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('merchant_order_no', $merchantOrderNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 统计商户下的业务订单数量。
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}

