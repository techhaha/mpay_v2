<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付插件模型。
 * 插件编码使用字符串主键 code，负责描述第三方支付实现能力。
 */
class PaymentPlugin extends BaseModel
{
    protected $table = 'ma_payment_plugin';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'class_name',
        'config_schema',
        'pay_types',
        'transfer_types',
        'version',
        'author',
        'link',
        'status',
        'remark',
    ];

    protected $casts = [
        'config_schema' => 'array',
        'pay_types' => 'array',
        'transfer_types' => 'array',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


