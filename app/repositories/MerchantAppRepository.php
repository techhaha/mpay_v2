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
            ->where('app_code', $appId)
            ->where('status', 1)
            ->first();
    }
    
    /**
     * 根据商户ID和应用ID查询
     */
    public function findByMerchantAndApp(int $merchantId, int $appId): ?MerchantApp
    {
        return $this->model->newQuery()
            ->where('mer_id', $merchantId)
            ->where('id', $appId)
            ->where('status', 1)
            ->first();
    }

    /**
     * 根据商户ID和应用ID（app_id）查询
     */
    public function findByMerchantAndAppId(int $merchantId, string $appId): ?MerchantApp
    {
        return $this->model->newQuery()
            ->where('mer_id', $merchantId)
            ->where('app_code', $appId)
            ->first();
    }

    /**
     * 后台按 app_id 查询（不过滤状态）
     */
    public function findAnyByAppId(string $appId): ?MerchantApp
    {
        return $this->model->newQuery()
            ->where('app_code', $appId)
            ->first();
    }

    /**
     * 后台列表：支持筛选与模糊搜索
     */
    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->model->newQuery();

        if (!empty($filters['merchant_id'])) {
            $query->where('mer_id', (int)$filters['merchant_id']);
        }
        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['app_id'])) {
            $query->where('app_code', 'like', '%' . $filters['app_id'] . '%');
        }
        if (!empty($filters['app_name'])) {
            $query->where('app_name', 'like', '%' . $filters['app_name'] . '%');
        }
        if (!empty($filters['api_type'])) {
            $query->where('api_type', (string)$filters['api_type']);
        }
        if (!empty($filters['package_code'])) {
            $query->where('package_code', (string)$filters['package_code']);
        }
        if (($filters['notify_enabled'] ?? '') !== '' && $filters['notify_enabled'] !== null) {
            $query->where('notify_enabled', (int)$filters['notify_enabled']);
        }
        if (!empty($filters['callback_mode'])) {
            $query->where('callback_mode', (string)$filters['callback_mode']);
        }

        $query->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }
}

