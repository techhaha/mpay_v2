<?php

namespace app\repository\account\balance;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantAccount;

/**
 * 商户余额账户仓库。
 *
 * 封装商户余额账户的单条查询、加锁查询和存在性统计。
 */
class MerchantAccountRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new MerchantAccount());
    }

    /**
     * 根据商户 ID 查询余额账户。
     *
     * @param int $merchantId 商户ID
     * @param array $columns 字段列表
     * @return MerchantAccount|null 账户记录
     */
    public function findByMerchantId(int $merchantId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->first($columns);
    }

    /**
     * 根据商户 ID 加锁查询余额账户。
     *
     * @param int $merchantId 商户ID
     * @param array $columns 字段列表
     * @return MerchantAccount|null 账户记录
     */
    public function findForUpdateByMerchantId(int $merchantId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->lockForUpdate()
            ->first($columns);
    }

    /**
     * 统计商户是否存在资金账户。
     *
     * @param int $merchantId 商户ID
     * @return int 账户数量
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}




