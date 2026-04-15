<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 商户通知参数校验器。
 *
 * 用于校验商户通知任务入参。
 */
class NotifyMerchantValidator extends Validator
{
    protected array $rules = [
        'notify_no' => 'sometimes|string|min:1|max:64',
        'merchant_id' => 'required|integer|min:1|exists:ma_merchant,id',
        'merchant_group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
        'biz_no' => 'required|string|min:1|max:64',
        'pay_no' => 'sometimes|string|min:1|max:64',
        'notify_url' => 'required|url|max:255',
        'notify_data' => 'nullable|array',
        'status' => 'sometimes|integer|min:0',
        'retry_count' => 'sometimes|integer|min:0',
        'next_retry_at' => 'nullable|date_format:Y-m-d H:i:s',
        'last_notify_at' => 'nullable|date_format:Y-m-d H:i:s',
        'last_response' => 'nullable|string|max:255',
    ];

    protected array $attributes = [
        'notify_no' => '通知单号',
        'merchant_id' => '商户ID',
        'merchant_group_id' => '商户分组ID',
        'biz_no' => '业务单号',
        'pay_no' => '支付单号',
        'notify_url' => '通知地址',
        'notify_data' => '通知内容',
        'status' => '状态',
        'retry_count' => '重试次数',
        'next_retry_at' => '下次重试时间',
        'last_notify_at' => '最后通知时间',
        'last_response' => '最后响应',
    ];

    protected array $scenes = [
        'store' => ['notify_no', 'merchant_id', 'merchant_group_id', 'biz_no', 'pay_no', 'notify_url', 'notify_data', 'status', 'retry_count', 'next_retry_at', 'last_notify_at', 'last_response'],
    ];
}
