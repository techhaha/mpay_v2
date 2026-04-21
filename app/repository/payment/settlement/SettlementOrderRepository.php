<?php

namespace app\repository\payment\settlement;

use app\common\base\BaseRepository;
use app\model\payment\SettlementOrder;

/**
 * 清算单仓库。
 *
 * 封装清算单号、追踪号、清算周期和最近列表查询。
 */
class SettlementOrderRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new SettlementOrder());
    }

    /**
     * 根据清算单号查询清算单。
     *
     * @param string $settleNo 结算单号
     * @param array $columns 字段列表
     * @return SettlementOrder|null 清算单记录
     */
    public function findBySettleNo(string $settleNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('settle_no', $settleNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询清算单。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return SettlementOrder|null 清算单记录
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->first($columns);
    }

    /**
     * 根据追踪号查询清结算单列表。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, SettlementOrder> 清算单列表
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
     *
     * @param int $merchantId 商户ID
     * @param int $channelId 渠道ID
     * @param int $cycleType 周期类型
     * @param string $cycleKey 周期标识
     * @param array $columns 字段列表
     * @return SettlementOrder|null 清算单记录
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
     *
     * @param string $settleNo 结算单号
     * @param array $columns 字段列表
     * @return SettlementOrder|null 清算单记录
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
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return SettlementOrder|null 清算单记录
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
     *
     * @param int $merchantId 商户ID
     * @param int $limit 限制条数
     * @return \Illuminate\Database\Eloquent\Collection<int, SettlementOrder> 最近清算单列表
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
     *
     * @param int $merchantId 商户ID
     * @return int 清算单数量
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}




