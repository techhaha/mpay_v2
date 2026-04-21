<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPlugin;

/**
 * 支付插件仓库。
 *
 * 封装支付插件字典的查询与启用列表读取。
 */
class PaymentPluginRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentPlugin());
    }

    /**
     * 根据插件编码查询支付插件。
     *
     * @param string $code 插件编码
     * @param array $columns 字段列表
     * @return PaymentPlugin|null 插件记录
     */
    public function findByCode(string $code, array $columns = ['*']): ?PaymentPlugin
    {
        return $this->model->newQuery()
            ->whereKey($code)
            ->first($columns);
    }

    /**
     * 获取所有启用的支付插件。
     *
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentPlugin> 启用插件列表
     */
    public function enabledList(array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('code', 'asc')
            ->get($columns);
    }
}






