<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付插件配置模型。
 * 保存插件初始化参数和默认结算周期等配置。
 */
class PaymentPluginConf extends BaseModel
{
    protected $table = 'ma_payment_plugin_conf';

    protected $fillable = [
        'plugin_code',
        'config',
        'settlement_cycle_type',
        'settlement_cutoff_time',
        'remark',
    ];

    protected $casts = [
        'config' => 'array',
        'settlement_cycle_type' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


