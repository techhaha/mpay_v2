<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * ePay V2 请求验证器。
 *
 * 定义新版支付、退款、商户与转账接口的场景规则。
 */
class EpayV2Validator extends Validator
{
    protected array $rules = [
        'pid' => 'required|integer|min:1',
        'timestamp' => 'required|integer|min:1',
        // 兼容旧版 SDK 里使用的 `RSA` 简写，同时内部统一按 SHA256WithRSA 验签。
        'sign_type' => 'required|string|in:SHA256WithRSA,RSA',
        // RSA 签名是 Base64 文本，长度会明显超过 MD5，不能沿用 255 的短限制。
        'sign' => 'required|string|max:2048',
        'type' => 'nullable|string|max:32',
        'method' => 'nullable|string|in:web,jump,jsapi,app,scan,applet',
        'trade_no' => 'nullable|string|max:64',
        'out_trade_no' => 'nullable|string|max:64',
        'notify_url' => 'nullable|string|max:255',
        'return_url' => 'nullable|string|max:255',
        'name' => 'nullable|string|max:255',
        'money' => 'nullable|regex:/^\d+(?:\.\d{1,2})?$/',
        'param' => 'nullable',
        'auth_code' => 'nullable|string|max:128',
        'sub_openid' => 'nullable|string|max:128',
        'sub_appid' => 'nullable|string|max:64',
        'clientip' => 'nullable|ip',
        'device' => 'nullable|string|in:pc,mobile,qq,wechat,alipay',
        'channel_id' => 'nullable|integer|min:0',
        'offset' => 'sometimes|integer|min:0',
        'limit' => 'sometimes|integer|min:1|max:50',
        'status' => 'nullable|integer|min:0|max:5',
        'refund_no' => 'nullable|string|max:64',
        'out_refund_no' => 'nullable|string|max:64',
        'biz_no' => 'nullable|string|max:32',
        'out_biz_no' => 'nullable|string|max:64',
        'account' => 'nullable|string|max:100',
        'bookid' => 'nullable|string|max:64',
        'remark' => 'nullable|string|max:255',
    ];

    protected array $attributes = [
        'pid' => '商户ID',
        'timestamp' => '时间戳',
        'sign_type' => '签名类型',
        'sign' => '签名字符串',
        'type' => '支付方式',
        'method' => '接口类型',
        'trade_no' => '平台订单号',
        'out_trade_no' => '商户订单号',
        'notify_url' => '异步通知地址',
        'return_url' => '跳转通知地址',
        'name' => '商品名称',
        'money' => '商品金额',
        'param' => '业务扩展参数',
        'auth_code' => '授权码',
        'sub_openid' => '子用户 OPENID',
        'sub_appid' => '子应用 APPID',
        'clientip' => '用户 IP',
        'device' => '设备类型',
        'channel_id' => '渠道ID',
        'offset' => '偏移量',
        'limit' => '数量',
        'status' => '状态',
        'refund_no' => '退款单号',
        'out_refund_no' => '商户退款单号',
        'biz_no' => '平台业务号',
        'out_biz_no' => '商户转账单号',
        'account' => '收款账号',
        'bookid' => '书签ID',
        'remark' => '备注',
    ];

    protected array $scenes = [
        'submit' => ['pid', 'timestamp', 'sign_type', 'sign', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'param', 'channel_id'],
        'create' => ['pid', 'timestamp', 'sign_type', 'sign', 'type', 'method', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'param', 'auth_code', 'sub_openid', 'sub_appid', 'clientip', 'device', 'channel_id'],
        'query' => ['pid', 'timestamp', 'sign_type', 'sign', 'trade_no', 'out_trade_no'],
        'refund' => ['pid', 'timestamp', 'sign_type', 'sign', 'trade_no', 'out_trade_no', 'money', 'out_refund_no'],
        'refund_query' => ['pid', 'timestamp', 'sign_type', 'sign', 'refund_no', 'out_refund_no'],
        'close' => ['pid', 'timestamp', 'sign_type', 'sign', 'trade_no', 'out_trade_no'],
        'merchant_info' => ['pid', 'timestamp', 'sign_type', 'sign'],
        'merchant_orders' => ['pid', 'timestamp', 'sign_type', 'sign', 'offset', 'limit', 'status'],
        'transfer_submit' => ['pid', 'timestamp', 'sign_type', 'sign', 'type', 'account', 'name', 'money', 'out_biz_no', 'remark', 'bookid'],
        'transfer_query' => ['pid', 'timestamp', 'sign_type', 'sign', 'biz_no', 'out_biz_no'],
        'transfer_balance' => ['pid', 'timestamp', 'sign_type', 'sign'],
    ];

    /**
     * 页面跳转支付场景。
     *
     * @return static
     */
    public function sceneSubmit(): static
    {
        return $this->appendRules([
            'type' => 'nullable|string|max:32',
            'out_trade_no' => 'required|string|max:64',
            'notify_url' => 'required|string|max:255',
            'return_url' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'money' => 'required|regex:/^\d+(?:\.\d{1,2})?$/',
            'sign_type' => 'required|string|in:SHA256WithRSA,RSA',
            'sign' => 'required|string|max:2048',
        ]);
    }

    /**
     * API 下单场景。
     *
     * @return static
     */
    public function sceneCreate(): static
    {
        return $this->appendRules([
            'type' => 'required|string|max:32',
            'method' => 'required|string|in:web,jump,jsapi,app,scan,applet',
            'out_trade_no' => 'required|string|max:64',
            'notify_url' => 'required|string|max:255',
            'return_url' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'money' => 'required|regex:/^\d+(?:\.\d{1,2})?$/',
            'device' => 'nullable|string|in:pc,mobile,qq,wechat,alipay',
            'sign_type' => 'required|string|in:SHA256WithRSA,RSA',
            'sign' => 'required|string|max:2048',
        ]);
    }

    /**
     * 退款发起场景。
     *
     * @return static
     */
    public function sceneRefund(): static
    {
        return $this->appendRules([
            'money' => 'required|regex:/^\d+(?:\.\d{1,2})?$/',
            'trade_no' => 'nullable|string|max:64|required_without:out_trade_no',
            'out_trade_no' => 'nullable|string|max:64|required_without:trade_no',
            'out_refund_no' => 'nullable|string|max:64',
            'sign_type' => 'required|string|in:SHA256WithRSA,RSA',
            'sign' => 'required|string|max:2048',
        ]);
    }

    /**
     * 支付单查询场景。
     *
     * @return static
     */
    public function sceneQuery(): static
    {
        return $this->appendRules([
            'trade_no' => 'nullable|string|max:64|required_without:out_trade_no',
            'out_trade_no' => 'nullable|string|max:64|required_without:trade_no',
        ]);
    }

    /**
     * 关闭订单场景。
     *
     * @return static
     */
    public function sceneClose(): static
    {
        return $this->sceneQuery();
    }

    /**
     * 退款查询场景。
     *
     * @return static
     */
    public function sceneRefundQuery(): static
    {
        return $this->appendRules([
            'refund_no' => 'nullable|string|max:64|required_without:out_refund_no',
            'out_refund_no' => 'nullable|string|max:64|required_without:refund_no',
        ]);
    }

    /**
     * 转账查询场景。
     *
     * @return static
     */
    public function sceneTransferQuery(): static
    {
        return $this->appendRules([
            'biz_no' => 'nullable|string|max:32|required_without:out_biz_no',
            'out_biz_no' => 'nullable|string|max:64|required_without:biz_no',
        ]);
    }

    /**
     * 转账发起场景。
     *
     * @return static
     */
    public function sceneTransferSubmit(): static
    {
        return $this->appendRules([
            'type' => 'required|string|in:alipay,wxpay,qqpay,bank',
            'account' => 'required|string|max:100',
            'name' => 'required|string|max:100',
            'money' => 'required|regex:/^\d+(?:\.\d{1,2})?$/',
            'sign_type' => 'required|string|in:SHA256WithRSA,RSA',
            'sign' => 'required|string|max:2048',
        ]);
    }
}
