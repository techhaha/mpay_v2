<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 路由解析参数校验器。
 *
 * 用于校验路由预览所需参数。
 */
class RouteResolveValidator extends Validator
{
    protected array $rules = [
        'merchant_group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
        'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
        'pay_amount' => 'required|integer|min:1',
        'stat_date' => 'nullable|date_format:Y-m-d',
    ];

    protected array $attributes = [
        'merchant_group_id' => '商户分组ID',
        'pay_type_id' => '支付方式',
        'pay_amount' => '支付金额',
        'stat_date' => '统计日期',
    ];

    protected array $scenes = [
        'resolve' => ['merchant_group_id', 'pay_type_id', 'pay_amount', 'stat_date'],
    ];
}
