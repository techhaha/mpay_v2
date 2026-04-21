<?php

namespace app\repository\account\ledger;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantAccountLedger;

/**
 * 商户余额流水仓库。
 *
 * 封装幂等键、追踪号、业务单号和商户维度的流水查询。
 */
class MerchantAccountLedgerRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new MerchantAccountLedger());
    }

    /**
     * 根据幂等键查询流水记录。
     *
     * @param string $idempotencyKey 幂等键
     * @param array $columns 字段列表
     * @return MerchantAccountLedger|null 流水记录
     */
    public function findByIdempotencyKey(string $idempotencyKey, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('idempotency_key', $idempotencyKey)
            ->first($columns);
    }

    /**
     * 根据追踪号查询流水记录。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return MerchantAccountLedger|null 流水记录
     */
    public function findByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->orderByDesc('id')
            ->first($columns);
    }

    /**
     * 查询指定追踪号的流水列表。
     *
     * @param string $traceNo 追踪号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, MerchantAccountLedger> 流水列表
     */
    public function listByTraceNo(string $traceNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('trace_no', $traceNo)
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 查询指定业务单号的流水列表。
     *
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, MerchantAccountLedger> 流水列表
     */
    public function listByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 按商户、业务类型和业务单号查询流水列表。
     *
     * @param int $merchantId 商户ID
     * @param int $bizType 业务类型
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, MerchantAccountLedger> 流水列表
     */
    public function listByMerchantAndBiz(int $merchantId, int $bizType, string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('biz_type', $bizType)
            ->where('biz_no', $bizNo)
            ->orderByDesc('id')
            ->get($columns);
    }
}





