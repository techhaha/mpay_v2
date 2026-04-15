<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付轮询组模型。
 * 用于将支付方式映射到一组可路由通道。
 */
class PaymentPollGroup extends BaseModel
{
    protected $table = 'ma_payment_poll_group';

    protected $fillable = [
        'group_name',
        'pay_type_id',
        'route_mode',
        'status',
        'remark',
    ];

    protected $casts = [
        'pay_type_id' => 'integer',
        'route_mode' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

