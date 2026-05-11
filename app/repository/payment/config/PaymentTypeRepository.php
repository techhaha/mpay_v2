<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentType;

/**
 * 支付方式字典仓库。
 *
 * 封装支付方式启用列表和按编码查询方法。
 */
class PaymentTypeRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentType());
    }

    /**
     * 获取所有启用的支付方式。
     *
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentType> 启用支付方式列表
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
     *
     * @param string $code 支付方式编码
     * @param array $columns 字段列表
     * @return PaymentType|null 支付方式记录
     */
    public function findByCode(string $code, array $columns = ['*']): ?PaymentType
    {
        return $this->model->newQuery()
            ->where('code', $code)
            ->first($columns);
    }

    /**
     * 根据支付方式 ID 批量查询字典。
     *
     * @param array<int, int> $ids 支付方式 ID 列表
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentType> 支付方式列表
     */
    public function listByIds(array $ids, array $columns = ['*'])
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return $this->model->newCollection();
        }

        return $this->model->newQuery()
            ->whereIn('id', $ids)
            ->get($columns);
    }
}





