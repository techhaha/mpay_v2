<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 支付回调参数校验器。
 *
 * 用于校验渠道回调和主动查单回传参数。
 */
class PayCallbackValidator extends Validator
{
    protected array $rules = [
        'pay_no' => 'required|string|min:1|max:64|exists:ma_pay_order,pay_no',
        'success' => 'required|boolean',
        'channel_id' => 'nullable|integer|min:1|exists:ma_payment_channel,id',
        'callback_type' => 'nullable|integer|in:0,1',
        'request_data' => 'nullable|array',
        'verify_status' => 'nullable|integer|in:0,1,2',
        'process_status' => 'nullable|integer|in:0,1,2',
        'process_result' => 'nullable|array',
        'channel_trade_no' => 'nullable|string|max:64',
        'channel_order_no' => 'nullable|string|max:64',
        'fee_actual_amount' => 'nullable|integer|min:0',
        'paid_at' => 'nullable|date_format:Y-m-d H:i:s',
        'channel_error_code' => 'nullable|string|max:64',
        'channel_error_msg' => 'nullable|string|max:255',
        'ext_json' => 'nullable|array',
    ];

    protected array $attributes = [
        'pay_no' => '支付单号',
        'success' => '是否成功',
        'channel_id' => '通道ID',
        'callback_type' => '回调类型',
        'request_data' => '原始回调数据',
        'verify_status' => '验签状态',
        'process_status' => '处理状态',
        'process_result' => '处理结果',
        'channel_trade_no' => '渠道交易号',
        'channel_order_no' => '渠道订单号',
        'fee_actual_amount' => '实际手续费',
        'paid_at' => '支付时间',
        'channel_error_code' => '错误码',
        'channel_error_msg' => '错误信息',
        'ext_json' => '扩展信息',
    ];

    protected array $scenes = [
        'callback' => ['pay_no', 'success', 'channel_id', 'callback_type', 'request_data', 'verify_status', 'process_status', 'process_result', 'channel_trade_no', 'channel_order_no', 'fee_actual_amount', 'paid_at', 'channel_error_code', 'channel_error_msg', 'ext_json'],
    ];
}
