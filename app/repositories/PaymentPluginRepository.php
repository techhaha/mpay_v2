<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentPlugin;

/**
 * 支付插件仓储
 */
class PaymentPluginRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentPlugin());
    }

    public function getActivePlugins()
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->get(['plugin_code', 'class_name']);
    }

    public function findActiveByCode(string $pluginCode): ?PaymentPlugin
    {
        return $this->model->newQuery()
            ->where('plugin_code', $pluginCode)
            ->where('status', 1)
            ->first();
    }
}
