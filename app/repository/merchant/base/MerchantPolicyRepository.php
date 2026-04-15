<?php

namespace app\repository\merchant\base;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantPolicy;

/**
 * 商户策略仓库。
 */
class MerchantPolicyRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new MerchantPolicy());
    }

    /**
     * 根据商户 ID 查询商户策略。
     */
    public function findByMerchantId(int $merchantId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->first($columns);
    }
}


