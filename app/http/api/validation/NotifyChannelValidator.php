<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 渠道通知参数校验器。
 *
 * 用于校验渠道通知日志入参。
 */
class NotifyChannelValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'notify_no' => 'sometimes|string|min:1|max:64',
        'channel_id' => 'required|integer|min:1|exists:ma_payment_channel,id',
        'notify_type' => 'sometimes|integer|in:0,1',
        'biz_no' => 'required|string|min:1|max:64',
        'pay_no' => 'sometimes|string|min:1|max:64',
        'channel_request_no' => 'sometimes|string|min:1|max:64',
        'channel_trade_no' => 'sometimes|string|min:1|max:64',
        'raw_payload' => 'nullable|array',
        'verify_status' => 'sometimes|integer|in:0,1,2',
        'process_status' => 'sometimes|integer|in:0,1,2',
        'retry_count' => 'sometimes|integer|min:0',
        'next_retry_at' => 'nullable|date_format:Y-m-d H:i:s',
        'last_error' => 'nullable|string|max:255',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'notify_no' => '通知单号',
        'channel_id' => '通道ID',
        'notify_type' => '通知类型',
        'biz_no' => '业务单号',
        'pay_no' => '支付单号',
        'channel_request_no' => '渠道请求号',
        'channel_trade_no' => '渠道交易号',
        'raw_payload' => '原始通知数据',
        'verify_status' => '验签状态',
        'process_status' => '处理状态',
        'retry_count' => '重试次数',
        'next_retry_at' => '下次重试时间',
        'last_error' => '最后错误',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'store' => ['notify_no', 'channel_id', 'notify_type', 'biz_no', 'pay_no', 'channel_request_no', 'channel_trade_no', 'raw_payload', 'verify_status', 'process_status', 'retry_count', 'next_retry_at', 'last_error'],
    ];
}


