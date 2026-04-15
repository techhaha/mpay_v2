<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPlugin;

/**
 * 支付插件仓库。
 */
class PaymentPluginRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PaymentPlugin());
    }

    /**
     * 根据插件编码查询支付插件。
     */
    public function findByCode(string $code, array $columns = ['*']): ?PaymentPlugin
    {
        return $this->model->newQuery()
            ->whereKey($code)
            ->first($columns);
    }

    /**
     * 获取所有启用的支付插件。
     */
    public function enabledList(array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('code', 'asc')
            ->get($columns);
    }
}


