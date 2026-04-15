<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 支付预下单参数校验器。
 *
 * 用于校验支付发起时的核心入参。
 */
class PayPrepareValidator extends Validator
{
    protected array $rules = [
        'merchant_id' => 'required|integer|min:1|exists:ma_merchant,id',
        'merchant_order_no' => 'required|string|min:1|max:64',
        'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
        'pay_amount' => 'required|integer|min:1',
        'subject' => 'sometimes|string|max:255',
        'body' => 'sometimes|string|max:500',
        'ext_json' => 'nullable|array',
    ];

    protected array $attributes = [
        'merchant_id' => '商户ID',
        'merchant_order_no' => '商户订单号',
        'pay_type_id' => '支付方式',
        'pay_amount' => '支付金额',
        'subject' => '标题',
        'body' => '描述',
        'ext_json' => '扩展信息',
    ];

    protected array $scenes = [
        'prepare' => ['merchant_id', 'merchant_order_no', 'pay_type_id', 'pay_amount', 'subject', 'body', 'ext_json'],
    ];
}
