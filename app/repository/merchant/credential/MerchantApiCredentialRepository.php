<?php

namespace app\repository\merchant\credential;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantApiCredential;

/**
 * 商户 API 凭证仓库。
 */
class MerchantApiCredentialRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new MerchantApiCredential());
    }

    /**
     * 根据商户 ID 查询 API 凭证。
     */
    public function findByMerchantId(int $merchantId, array $columns = ['*']): ?MerchantApiCredential
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->first($columns);
    }

    /**
     * 统计商户是否已开通 API 凭证。
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}

