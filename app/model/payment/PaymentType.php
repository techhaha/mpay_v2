<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付方式字典模型。
 * 用于维护支付方式编码、名称、排序和启停状态。
 */
class PaymentType extends BaseModel
{
    protected $table = 'ma_payment_type';

    protected $fillable = [
        'code',
        'name',
        'icon',
        'sort_no',
        'status',
        'remark',
    ];

    protected $casts = [
        'sort_no' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


