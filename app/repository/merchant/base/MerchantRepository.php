<?php

namespace app\repository\merchant\base;

use app\common\base\BaseRepository;
use app\model\merchant\Merchant;

/**
 * 商户仓库。
 */
class MerchantRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new Merchant());
    }

    /**
     * 根据商户编号查询商户。
     */
    public function findByMerchantNo(string $merchantNo, array $columns = ['*']): ?Merchant
    {
        return $this->model->newQuery()
            ->where('merchant_no', $merchantNo)
            ->first($columns);
    }

    /**
     * 获取所有启用的商户。
     */
    public function enabledList(array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->get($columns);
    }
}


