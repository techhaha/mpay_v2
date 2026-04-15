<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 商户分组与轮询组绑定模型。
 * 用于确定某商户分组在某支付方式下的默认路由组。
 */
class PaymentPollGroupBind extends BaseModel
{
    protected $table = 'ma_payment_poll_group_bind';

    protected $fillable = [
        'merchant_group_id',
        'pay_type_id',
        'poll_group_id',
        'status',
        'remark',
    ];

    protected $casts = [
        'merchant_group_id' => 'integer',
        'pay_type_id' => 'integer',
        'poll_group_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


