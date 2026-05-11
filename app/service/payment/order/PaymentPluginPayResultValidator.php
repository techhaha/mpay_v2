<?php

namespace app\service\payment\order;

use support\validation\Validator;

/**
 * 插件支付返回值验证器。
 *
 * 只声明插件 pay() 返回结构的字段规则，具体订单处理由派发服务完成。
 */
class PaymentPluginPayResultValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array<string, string>
     */
    protected array $rules = [
        'pay_page' => 'required|string|in:qrcode,html,jump,jsapi,urlscheme,error,ok,page|max:32',
        'pay_type' => 'required|string|max:32',
        'pay_product' => 'required|string|max:64',
        'pay_action' => 'required|string|max:64',
        'pay_params' => 'required|array',
        'chan_order_no' => 'required_unless:pay_page,error,html,ok|string|max:64',
        'chan_trade_no' => 'nullable|string|max:64',
    ];

    /**
     * 字段别名
     *
     * @var array<string, string>
     */
    protected array $attributes = [
        'pay_page' => '承接页类型',
        'pay_type' => '支付方式',
        'pay_product' => '插件支付产品',
        'pay_action' => '插件支付动作',
        'pay_params' => '插件支付参数',
        'chan_order_no' => '渠道订单号',
        'chan_trade_no' => '渠道交易号',
    ];

    /**
     * 自定义错误消息
     *
     * @var array<string, string>
     */
    protected array $messages = [
        'pay_page.required' => '插件下单返回 pay_page 不能为空',
        'pay_page.in' => '插件下单返回 pay_page 不支持',
        'pay_type.required' => '插件下单返回 pay_type 不能为空',
        'pay_product.required' => '插件下单返回 pay_product 不能为空',
        'pay_action.required' => '插件下单返回 pay_action 不能为空',
        'pay_params.required' => '插件下单返回 pay_params 不能为空',
        'pay_params.array' => '插件下单返回 pay_params 必须为数组',
        'chan_order_no.required_unless' => '插件下单返回 chan_order_no 不能为空',
    ];

    /**
     * 校验场景
     *
     * @var array<string, array<int, string>>
     */
    protected array $scenes = [
        'pay_result' => [
            'pay_page',
            'pay_type',
            'pay_product',
            'pay_action',
            'pay_params',
            'chan_order_no',
            'chan_trade_no',
        ],
    ];
}
