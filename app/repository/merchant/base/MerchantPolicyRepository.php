<?php

namespace app\repository\merchant\base;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantPolicy;

/**
 * 商户策略仓库。
 *
 * 封装商户策略的基础查询。
 */
class MerchantPolicyRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new MerchantPolicy());
    }

    /**
     * 根据商户 ID 查询商户策略。
     *
     * @param int $merchantId 商户ID
     * @param array $columns 字段列表
     * @return MerchantPolicy|null 策略记录
     */
    public function findByMerchantId(int $merchantId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->first($columns);
    }
}






