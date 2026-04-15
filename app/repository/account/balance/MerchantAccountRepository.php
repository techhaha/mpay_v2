<?php

namespace app\repository\account\balance;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantAccount;

/**
 * 商户余额账户仓库。
 */
class MerchantAccountRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new MerchantAccount());
    }

    /**
     * 根据商户 ID 查询余额账户。
     */
    public function findByMerchantId(int $merchantId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->first($columns);
    }

    /**
     * 根据商户 ID 加锁查询余额账户。
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
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}

