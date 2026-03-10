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

    /**
     * 后台按 app_id 查询（不过滤状态）
     */
    public function findAnyByAppId(string $appId): ?MerchantApp
    {
        return $this->model->newQuery()
            ->where('app_id', $appId)
            ->first();
    }

    /**
     * 后台列表：支持筛选与模糊搜索
     */
    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->model->newQuery();

        if (!empty($filters['merchant_id'])) {
            $query->where('merchant_id', (int)$filters['merchant_id']);
        }
        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['app_id'])) {
            $query->where('app_id', 'like', '%' . $filters['app_id'] . '%');
        }
        if (!empty($filters['app_name'])) {
            $query->where('app_name', 'like', '%' . $filters['app_name'] . '%');
        }
        if (!empty($filters['api_type'])) {
            $query->where('api_type', (string)$filters['api_type']);
        }

        $query->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }
}

