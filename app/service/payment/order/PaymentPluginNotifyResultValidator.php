<?php

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件回调返回值验证器。
 *
 * 只声明插件 notify() 返回结构的字段规则，具体状态推进由回调服务完成。
 */
class PaymentPluginNotifyResultValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array<string, string>
     */
    protected array $rules = [
        'status' => 'required|string|in:success,failed,pending',
        'pay_no' => 'nullable|string|max:64',
        'message' => 'nullable|string',
        'channel_order_no' => 'required|string|max:64',
        'channel_trade_no' => 'required|string|max:64',
        'channel_status' => 'nullable|string|max:128',
        'channel_error_code' => 'nullable|string|max:64',
        'channel_error_msg' => 'nullable|string',
        'paid_at' => 'nullable',
        'failed_at' => 'nullable',
    ];

    /**
     * 字段别名
     *
     * @var array<string, string>
     */
    protected array $attributes = [
        'status' => '支付状态',
        'pay_no' => '支付单号',
        'message' => '回调说明',
        'channel_order_no' => '渠道订单号',
        'channel_trade_no' => '渠道交易号',
        'channel_status' => '渠道状态',
        'channel_error_code' => '渠道错误码',
        'channel_error_msg' => '渠道错误消息',
        'paid_at' => '支付成功时间',
        'failed_at' => '支付失败时间',
    ];

    /**
     * 自定义错误消息
     *
     * @var array<string, string>
     */
    protected array $messages = [
        'status.required' => '插件回调返回 status 不能为空',
        'status.in' => '插件回调返回的状态不合法',
        'channel_order_no.required' => '插件回调返回 channel_order_no 不能为空',
        'channel_trade_no.required' => '插件回调返回 channel_trade_no 不能为空',
    ];

    /**
     * 校验场景
     *
     * @var array<string, array<int, string>>
     */
    protected array $scenes = [
        'notify_result' => [
            'status',
            'pay_no',
            'message',
            'channel_order_no',
            'channel_trade_no',
            'channel_status',
            'channel_error_code',
            'channel_error_msg',
            'paid_at',
            'failed_at',
        ],
    ];
}
