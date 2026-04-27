<?php

namespace app\repository\payment\trade;

use app\common\base\BaseRepository;
use app\model\payment\TransferOrder;

/**
 * 转账单仓库。
 */
class TransferOrderRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new TransferOrder());
    }

    public function findByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->first($columns);
    }

    public function findByOutBizNo(int $merchantId, string $outBizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('out_biz_no', $outBizNo)
            ->first($columns);
    }

    public function findForUpdateByBizNo(string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('biz_no', $bizNo)
            ->lockForUpdate()
            ->first($columns);
    }

    public function findForUpdateByOutBizNo(int $merchantId, string $outBizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('out_biz_no', $outBizNo)
            ->lockForUpdate()
            ->first($columns);
    }
}

