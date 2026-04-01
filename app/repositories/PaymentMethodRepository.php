<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentMethod;

/**
 * 支付方式仓储
 */
class PaymentMethodRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentMethod());
    }

    public function getAllEnabled(): array
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('sort', 'asc')
            ->get()
            ->toArray();
    }

    public function findByCode(string $methodCode): ?PaymentMethod
    {
        return $this->model->newQuery()
            ->where('type', $methodCode)
            ->where('status', 1)
            ->first();
    }

    /**
     * 后台按 code 查询（不过滤状态）
     */
    public function findAnyByCode(string $methodCode): ?PaymentMethod
    {
        return $this->model->newQuery()
            ->where('type', $methodCode)
            ->first();
    }

    /**
     * 后台列表：支持筛选与排序
     */
    public function searchPaginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->model->newQuery();

        if (($filters['status'] ?? '') !== '' && $filters['status'] !== null) {
            $query->where('status', (int)$filters['status']);
        }
        if (!empty($filters['method_code'])) {
            $query->where('type', 'like', '%' . $filters['method_code'] . '%');
        }
        if (!empty($filters['method_name'])) {
            $query->where('name', 'like', '%' . $filters['method_name'] . '%');
        }

        $query->orderBy('sort', 'asc')->orderByDesc('id');

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }
}
