<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 退款动作参数校验器。
 *
 * 用于校验退款处理、失败和重试操作的公共参数。
 */
class RefundActionValidator extends Validator
{
    protected array $rules = [
        'refund_no' => 'required|string|min:1|max:64|exists:ma_refund_order,refund_no',
        'reason' => 'nullable|string|max:255',
        'last_error' => 'nullable|string|max:255',
        'processing_at' => 'nullable|date_format:Y-m-d H:i:s',
        'failed_at' => 'nullable|date_format:Y-m-d H:i:s',
        'ext_json' => 'nullable|array',
    ];

    protected array $attributes = [
        'refund_no' => '退款单号',
        'reason' => '原因',
        'last_error' => '最近错误信息',
        'processing_at' => '处理时间',
        'failed_at' => '失败时间',
        'ext_json' => '扩展信息',
    ];

    protected array $scenes = [
        'processing' => ['refund_no', 'reason', 'last_error', 'processing_at', 'ext_json'],
        'retry' => ['refund_no', 'reason', 'last_error', 'processing_at', 'ext_json'],
        'fail' => ['refund_no', 'reason', 'last_error', 'failed_at', 'ext_json'],
    ];
}
