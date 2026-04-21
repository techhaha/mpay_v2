<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付方式字典模型。
 * 用于维护支付方式编码、名称、排序和启停状态。
 */
class PaymentType extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_payment_type';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'code',
        'name',
        'icon',
        'sort_no',
        'status',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'sort_no' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




