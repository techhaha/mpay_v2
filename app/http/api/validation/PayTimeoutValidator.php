<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 支付超时参数校验器。
 *
 * 用于校验超时关闭支付单所需参数。
 */
class PayTimeoutValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'pay_no' => 'required|string|min:1|max:64|exists:ma_pay_order,pay_no',
        'reason' => 'nullable|string|max:255',
        'timeout_at' => 'nullable|date_format:Y-m-d H:i:s',
        'ext_json' => 'nullable|array',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'pay_no' => '支付单号',
        'reason' => '超时原因',
        'timeout_at' => '超时时间',
        'ext_json' => '扩展信息',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'timeout' => ['pay_no', 'reason', 'timeout_at', 'ext_json'],
    ];
}


