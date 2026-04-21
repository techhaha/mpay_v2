<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付插件配置模型。
 * 保存插件初始化参数和默认结算周期等配置。
 */
class PaymentPluginConf extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_payment_plugin_conf';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'plugin_code',
        'config',
        'settlement_cycle_type',
        'settlement_cutoff_time',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'config' => 'array',
        'settlement_cycle_type' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




