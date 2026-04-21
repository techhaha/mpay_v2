<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * ePay 兼容层请求校验器。
 *
 * 用于校验兼容层不同入口的请求参数。
 */
class EpayValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'act' => 'required|string|in:query,settle,order,orders,refund',
        'pid' => 'required|integer|gt:0',
        'key' => 'required|string|min:1|max:255',
        'type' => 'sometimes|string|max:32',
        'out_trade_no' => 'sometimes|string|min:1|max:64',
        'notify_url' => 'sometimes|url|max:255',
        'return_url' => 'sometimes|url|max:255',
        'name' => 'sometimes|string|min:1|max:255',
        'money' => 'sometimes|numeric|gt:0|regex:/^\d+(?:\.\d{1,2})?$/',
        'sign' => 'sometimes|string|min:1|max:255',
        'sign_type' => 'sometimes|string|in:MD5,md5',
        'device' => 'sometimes|string|in:pc,mobile,qq,wechat,alipay,jump',
        'clientip' => 'sometimes|ip',
        'param' => 'sometimes|string|max:2000',
        'trade_no' => 'sometimes|string|min:1|max:64',
        'refund_no' => 'sometimes|string|min:1|max:64',
        'reason' => 'sometimes|string|max:255',
        'limit' => 'sometimes|integer|gt:0|max:50',
        'page' => 'sometimes|integer|gt:0',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'act' => '操作类型',
        'pid' => '商户ID',
        'key' => '商户密钥',
        'type' => '支付方式',
        'out_trade_no' => '商户订单号',
        'trade_no' => '易支付订单号',
        'notify_url' => '异步通知地址',
        'return_url' => '跳转通知地址',
        'name' => '商品名称',
        'money' => '商品金额',
        'sign' => '签名字符串',
        'sign_type' => '签名类型',
        'device' => '设备类型',
        'clientip' => '用户IP地址',
        'param' => '业务扩展参数',
        'refund_no' => '退款单号',
        'reason' => '退款原因',
        'limit' => '查询订单数量',
        'page' => '页码',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'submit' => ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'sign', 'sign_type', 'param'],
        'mapi' => ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'clientip', 'device', 'sign', 'sign_type', 'param'],
        'query' => ['act', 'pid', 'key'],
        'settle' => ['act', 'pid', 'key'],
        'order_trade_no' => ['act', 'pid', 'key', 'trade_no'],
        'order_out_trade_no' => ['act', 'pid', 'key', 'out_trade_no'],
        'orders' => ['act', 'pid', 'key', 'limit', 'page'],
        'refund_trade_no' => ['act', 'pid', 'key', 'trade_no', 'money', 'refund_no', 'reason'],
        'refund_out_trade_no' => ['act', 'pid', 'key', 'out_trade_no', 'money', 'refund_no', 'reason'],
    ];

    /**
     * 根据场景返回 ePay 兼容层校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'submit' => array_merge($rules, [
                'type' => 'sometimes|string|max:32',
                'out_trade_no' => 'required|string|min:1|max:64',
                'notify_url' => 'required|url|max:255',
                'return_url' => 'required|url|max:255',
                'name' => 'required|string|min:1|max:255',
                'money' => 'required|numeric|gt:0|regex:/^\d+(?:\.\d{1,2})?$/',
                'sign' => 'required|string|min:1|max:255',
                'sign_type' => 'required|string|in:MD5,md5',
            ]),
            'mapi' => array_merge($rules, [
                'type' => 'required|string|max:32',
                'out_trade_no' => 'required|string|min:1|max:64',
                'notify_url' => 'required|url|max:255',
                'return_url' => 'sometimes|url|max:255',
                'name' => 'required|string|min:1|max:255',
                'money' => 'required|numeric|gt:0|regex:/^\d+(?:\.\d{1,2})?$/',
                'clientip' => 'required|ip',
                'sign' => 'required|string|min:1|max:255',
                'sign_type' => 'required|string|in:MD5,md5',
            ]),
            'order_trade_no' => array_merge($rules, [
                'trade_no' => 'required|string|min:1|max:64',
            ]),
            'order_out_trade_no' => array_merge($rules, [
                'out_trade_no' => 'required|string|min:1|max:64',
            ]),
            'refund_trade_no' => array_merge($rules, [
                'trade_no' => 'required|string|min:1|max:64',
                'money' => 'required|numeric|gt:0|regex:/^\d+(?:\.\d{1,2})?$/',
            ]),
            'refund_out_trade_no' => array_merge($rules, [
                'out_trade_no' => 'required|string|min:1|max:64',
                'money' => 'required|numeric|gt:0|regex:/^\d+(?:\.\d{1,2})?$/',
            ]),
            default => $rules,
        };
    }
}

