<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentType;

/**
 * 支付方式字典仓库。
 */
class PaymentTypeRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PaymentType());
    }

    /**
     * 获取所有启用的支付方式。
     */
    public function enabledList(array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('sort_no')
            ->get($columns);
    }

    /**
     * 根据支付方式编码查询字典。
     */
    public function findByCode(string $code, array $columns = ['*']): ?PaymentType
    {
        return $this->model->newQuery()
            ->where('code', $code)
            ->first($columns);
    }
}


