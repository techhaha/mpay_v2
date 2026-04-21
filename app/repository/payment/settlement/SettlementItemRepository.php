<?php

namespace app\repository\payment\settlement;

use app\common\base\BaseRepository;
use app\model\payment\SettlementItem;

/**
 * 清算明细仓库。
 *
 * 封装清算单下的明细列表查询。
 */
class SettlementItemRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new SettlementItem());
    }

    /**
     * 查询指定清算单下的明细列表。
     *
     * @param string $settleNo 结算单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, SettlementItem> 清算明细列表
     */
    public function listBySettleNo(string $settleNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('settle_no', $settleNo)
            ->orderBy('id')
            ->get($columns);
    }
}






