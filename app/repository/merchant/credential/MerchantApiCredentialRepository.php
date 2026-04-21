<?php

namespace app\repository\merchant\credential;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantApiCredential;

/**
 * 商户 API 凭证仓库。
 *
 * 封装商户 API 凭证的单条查询与存在性统计。
 */
class MerchantApiCredentialRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new MerchantApiCredential());
    }

    /**
     * 根据商户 ID 查询 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @param array $columns 字段列表
     * @return MerchantApiCredential|null 凭证记录
     */
    public function findByMerchantId(int $merchantId, array $columns = ['*']): ?MerchantApiCredential
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->first($columns);
    }

    /**
     * 统计商户是否已开通 API 凭证。
     *
     * @param int $merchantId 商户ID
     * @return int 凭证数量
     */
    public function countByMerchantId(int $merchantId): int
    {
        return (int) $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->count();
    }
}




