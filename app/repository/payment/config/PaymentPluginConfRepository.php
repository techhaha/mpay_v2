<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPluginConf;

/**
 * 支付插件配置仓库。
 *
 * 封装按插件编码读取最新配置的查询方法。
 */
class PaymentPluginConfRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentPluginConf());
    }

    /**
     * 根据插件编码查询插件配置。
     *
     * @param string $pluginCode 插件编码
     * @param array $columns 字段列表
     * @return PaymentPluginConf|null 插件配置记录
     */
    public function findByPluginCode(string $pluginCode, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('plugin_code', $pluginCode)
            ->orderByDesc('id')
            ->first($columns);
    }
}






