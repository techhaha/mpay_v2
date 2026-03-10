<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\Merchant;

/**
 * 商户仓储
 */
class MerchantRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Merchant());
    }
    
    /**
     * 根据商户号查询
     */
    public function findByMerchantNo(string $merchantNo): ?Merchant
    {
        return $this->model->newQuery()
            ->where('merchant_no', $merchantNo)
            ->first();
    }

    /**
     * 后台列表：支持模糊搜索
     */
    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->model->newQuery();

        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['merchant_no'])) {
            $query->where('merchant_no', 'like', '%' . $filters['merchant_no'] . '%');
        }
        if (!empty($filters['merchant_name'])) {
            $query->where('merchant_name', 'like', '%' . $filters['merchant_name'] . '%');
        }

        $query->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }
}

