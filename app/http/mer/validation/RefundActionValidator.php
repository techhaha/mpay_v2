<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 退款操作参数校验器。
 *
 * 仅供商户后台的退款重试等操作使用。
 */
class RefundActionValidator extends Validator
{
    protected array $rules = [
        'refund_no' => 'required|string|max:64',
        'processing_at' => 'sometimes|date_format:Y-m-d H:i:s',
        'failed_at' => 'sometimes|date_format:Y-m-d H:i:s',
        'last_error' => 'sometimes|string|max:512',
        'channel_refund_no' => 'sometimes|string|max:64',
    ];

    protected array $attributes = [
        'refund_no' => '退款单号',
        'processing_at' => '处理时间',
        'failed_at' => '失败时间',
        'last_error' => '错误信息',
        'channel_refund_no' => '渠道退款单号',
    ];

    protected array $scenes = [
        'retry' => ['refund_no', 'processing_at'],
        'mark_fail' => ['refund_no', 'failed_at', 'last_error'],
        'mark_processing' => ['refund_no', 'processing_at'],
        'mark_success' => ['refund_no', 'channel_refund_no'],
    ];
}
