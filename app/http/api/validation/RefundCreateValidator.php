<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 退款创建参数校验器。
 *
 * 用于校验退款单创建参数。
 */
class RefundCreateValidator extends Validator
{
    protected array $rules = [
        'pay_no' => 'required|string|min:1|max:64|exists:ma_pay_order,pay_no',
        'merchant_refund_no' => 'sometimes|string|min:1|max:64',
        'refund_amount' => 'nullable|integer|min:1',
        'reason' => 'nullable|string|max:255',
        'ext_json' => 'nullable|array',
    ];

    protected array $attributes = [
        'pay_no' => '支付单号',
        'merchant_refund_no' => '商户退款单号',
        'refund_amount' => '退款金额',
        'reason' => '退款原因',
        'ext_json' => '扩展信息',
    ];

    protected array $scenes = [
        'store' => ['pay_no', 'merchant_refund_no', 'refund_amount', 'reason', 'ext_json'],
    ];
}
