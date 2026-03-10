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
            ->where('method_code', $methodCode)
            ->where('status', 1)
            ->first();
    }
}
