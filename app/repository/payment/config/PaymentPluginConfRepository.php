<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPluginConf;

/**
 * 支付插件配置仓库。
 */
class PaymentPluginConfRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PaymentPluginConf());
    }

    /**
     * 根据插件编码查询插件配置。
     */
    public function findByPluginCode(string $pluginCode, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('plugin_code', $pluginCode)
            ->orderByDesc('id')
            ->first($columns);
    }
}


