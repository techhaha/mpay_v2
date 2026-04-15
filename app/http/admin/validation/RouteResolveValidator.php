<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 路由解析参数校验器。
 *
 * 仅供管理后台预览路由使用。
 */
class RouteResolveValidator extends Validator
{
    protected array $rules = [
        'merchant_group_id' => 'required|integer|min:1',
        'pay_type_id' => 'required|integer|min:1',
        'pay_amount' => 'required|integer|min:1',
        'pay_type_code' => 'sometimes|string|max:32',
        'channel_mode' => 'sometimes|integer|in:0,1',
        'stat_date' => 'sometimes|date_format:Y-m-d',
    ];

    protected array $attributes = [
        'merchant_group_id' => '商户分组',
        'pay_type_id' => '支付方式',
        'pay_amount' => '支付金额',
        'pay_type_code' => '支付方式编码',
        'channel_mode' => '通道模式',
        'stat_date' => '统计日期',
    ];

    protected array $scenes = [
        'resolve' => ['merchant_group_id', 'pay_type_id', 'pay_amount', 'pay_type_code', 'channel_mode', 'stat_date'],
    ];
}
