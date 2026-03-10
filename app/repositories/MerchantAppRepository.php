<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\MerchantApp;

/**
 * 商户应用仓储
 */
class MerchantAppRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new MerchantApp());
    }
    
    /**
     * 根据AppId查询
     */
    public function findByAppId(string $appId): ?MerchantApp
    {
        return $this->model->newQuery()
            ->where('app_id', $appId)
            ->where('status', 1)
            ->first();
    }
    
    /**
     * 根据商户ID和应用ID查询
     */
    public function findByMerchantAndApp(int $merchantId, int $appId): ?MerchantApp
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('id', $appId)
            ->where('status', 1)
            ->first();
    }
}

