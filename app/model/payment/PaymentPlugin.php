<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付插件模型。
 * 插件编码使用字符串主键 code，负责描述第三方支付实现能力。
 */
class PaymentPlugin extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_payment_plugin';

    /**
     * 主键字段名
     *
     * @var mixed
     */
    protected $primaryKey = 'code';

    /**
     * incrementing
     *
     * @var mixed
     */
    public $incrementing = false;

    /**
     * key类型
     *
     * @var mixed
     */
    protected $keyType = 'string';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
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
        'allow_merchant',
        'status',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'config_schema' => 'array',
        'pay_types' => 'array',
        'transfer_types' => 'array',
        'allow_merchant' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}



