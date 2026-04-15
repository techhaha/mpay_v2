<?php

namespace app\repository\payment\settlement;

use app\common\base\BaseRepository;
use app\model\payment\SettlementItem;

/**
 * 清算明细仓库。
 */
class SettlementItemRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new SettlementItem());
    }

    /**
     * 查询指定清算单下的明细列表。
     */
    public function listBySettleNo(string $settleNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('settle_no', $settleNo)
            ->orderBy('id')
            ->get($columns);
    }
}


