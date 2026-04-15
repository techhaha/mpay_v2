<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 支付关闭参数校验器。
 *
 * 用于校验关闭支付单所需参数。
 */
class PayCloseValidator extends Validator
{
    protected array $rules = [
        'pay_no' => 'required|string|min:1|max:64|exists:ma_pay_order,pay_no',
        'reason' => 'nullable|string|max:255',
        'closed_at' => 'nullable|date_format:Y-m-d H:i:s',
        'ext_json' => 'nullable|array',
    ];

    protected array $attributes = [
        'pay_no' => '支付单号',
        'reason' => '关闭原因',
        'closed_at' => '关闭时间',
        'ext_json' => '扩展信息',
    ];

    protected array $scenes = [
        'close' => ['pay_no', 'reason', 'closed_at', 'ext_json'],
    ];
}
