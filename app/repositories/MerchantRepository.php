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
}

