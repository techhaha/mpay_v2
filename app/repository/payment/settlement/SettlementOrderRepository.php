<?php

namespace app\repository\payment\settlement;

use app\common\base\BaseRepository;
use app\model\payment\SettlementOrder;

/**
 * 清算单仓库。
 */
class SettlementOrderRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new SettlementOrder());
    }

    /**
     * 根据清算单号查询清算单。
     */
    public function findBySettleNo(string $settleNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('settle_no', $settleNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询清算单。
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询清结算单列表。
     */
    public function listByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 根据商户、通道和清算周期查询清算单。
     */
    public function findByCycle(int $merchantId, int $channelId, int $cycleType, string $cycleKey, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('channel_id', $channelId)
            ->where('cycle_type', $cycleType)
            ->where('cycle_key', $cycleKey)
            ->first($columns);
    }

    /**
     * 根据清算单号加锁查询清算单。
     */
    public function findForUpdateBySettleNo(string $settleNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('settle_no', $settleNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 根据追踪号加锁查询清算单。
     */
    public function findForUpdateByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 查询商户最近清算单列表，用于总览展示。
     */
    public function recentByMerchantId(int $merchantId, int $limit = 5)
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get([
                'settle_no',
                'net_amount',
                'status',
                'cycle_key',
                'created_at',
            ]);
    }

    /**
     * 统计商户下的清算单数量。
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}
