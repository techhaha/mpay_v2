<?php

namespace app\repository\merchant\base;

use app\common\base\BaseRepository;
use app\model\merchant\Merchant;

/**
 * 商户基础查询仓库。
 *
 * 封装按商户号、启用状态等基础条件读取商户记录的方法。
 */
class MerchantRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new Merchant());
    }

    /**
     * 根据商户编号查询商户。
     *
     * @param string $merchantNo 商户号
     * @param array $columns 字段列表
     * @return Merchant|null 商户记录
     */
    public function findByMerchantNo(string $merchantNo, array $columns = ['*']): ?Merchant
    {
        return $this->model->newQuery()
            ->where('merchant_no', $merchantNo)
            ->first($columns);
    }

    /**
     * 获取所有启用的商户。
     *
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, Merchant> 启用商户列表
     */
    public function enabledList(array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->get($columns);
    }
}








