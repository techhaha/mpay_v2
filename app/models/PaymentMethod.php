<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付方式模型
 *
 * 对应表：ma_pay_method
 */
class PaymentMethod extends BaseModel
{
    protected $table = 'ma_pay_method';

    protected $fillable = [
        'method_code',
        'method_name',
        'icon',
        'sort',
        'status',
    ];

    public $timestamps = true;

    protected $casts = [
        'sort' => 'integer',
        'status' => 'integer',
    ];
}
