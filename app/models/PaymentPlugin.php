<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付插件模型
 *
 * 对应表：ma_pay_plugin（主键 code）
 */
class PaymentPlugin extends BaseModel
{
    protected $table = 'ma_pay_plugin';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'class_name',
        'status',
        'config_schema',
        'pay_types',
        'transfer_types',
        'author',
        'link',
    ];

    public $timestamps = true;

    protected $casts = [
        'status' => 'integer',
        'config_schema' => 'array',
        'pay_types' => 'array',
        'transfer_types' => 'array',
    ];
}
