<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付插件模型
 *
 * 对应表：ma_pay_plugin（主键 plugin_code）
 */
class PaymentPlugin extends BaseModel
{
    protected $table = 'ma_pay_plugin';

    protected $primaryKey = 'plugin_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'plugin_code',
        'plugin_name',
        'class_name',
        'status',
    ];

    public $timestamps = true;

    protected $casts = [
        'status' => 'integer',
    ];
}
