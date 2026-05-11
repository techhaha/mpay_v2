<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * ePay V1 请求验证器。
 *
 * 定义旧版提交、查询、退款与兼容接口的场景规则。
 */
class EpayV1Validator extends Validator
{
    protected array $rules = [
        'act' => 'sometimes|string|in:query,settle,order,orders,refund',
        'pid' => 'required|integer|min:1',
        'key' => 'nullable|string|max:128',
        'type' => 'nullable|string|max:32',
        'trade_no' => 'nullable|string|max:64',
        'out_trade_no' => 'nullable|string|max:64',
        'notify_url' => 'nullable|string|max:255',
        'return_url' => 'nullable|string|max:255',
        'name' => 'nullable|string|max:255',
        'money' => 'nullable|regex:/^(?=.*[1-9])\d+(?:\.\d{1,2})?$/',
        'param' => 'nullable',
        'clientip' => 'nullable|ip',
        'device' => 'nullable|string|in:pc,mobile,qq,wechat,alipay,jump',
        'sign' => 'nullable|string|max:255',
        'sign_type' => 'sometimes|string|in:MD5',
        'page' => 'sometimes|integer|min:1',
        'limit' => 'sometimes|integer|min:1|max:50',
    ];

    protected array $attributes = [
        'act' => '操作类型',
        'pid' => '商户ID',
        'key' => '商户密钥',
        'type' => '支付方式',
        'trade_no' => '平台订单号',
        'out_trade_no' => '商户订单号',
        'notify_url' => '异步通知地址',
        'return_url' => '跳转通知地址',
        'name' => '商品名称',
        'money' => '商品金额',
        'param' => '业务扩展参数',
        'clientip' => '用户 IP',
        'device' => '设备类型',
        'sign' => '签名字符串',
        'sign_type' => '签名类型',
        'page' => '页码',
        'limit' => '数量',
    ];

    protected array $scenes = [
        'submit' => ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'param', 'sign', 'sign_type'],
        'mapi' => ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'param', 'clientip', 'device', 'sign', 'sign_type'],
        'api_query' => ['act', 'pid', 'key'],
        'api_settle' => ['act', 'pid', 'key'],
        'api_order' => ['act', 'pid', 'key', 'trade_no', 'out_trade_no'],
        'api_orders' => ['act', 'pid', 'key', 'page', 'limit'],
        'api_refund' => ['act', 'pid', 'key', 'trade_no', 'out_trade_no', 'money'],
    ];

    /**
     * 页面跳转支付场景。
     *
     * @return static
     */
    public function sceneSubmit(): static
    {
        return $this->appendRules([
            'out_trade_no' => 'required|string|max:64',
            'notify_url' => 'required|string|max:255',
            'return_url' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'money' => 'required|regex:/^(?=.*[1-9])\d+(?:\.\d{1,2})?$/',
            'sign_type' => 'required|string|in:MD5',
            'sign' => 'required|string|max:255',
        ]);
    }

    /**
     * API 支付场景。
     *
     * @return static
     */
    public function sceneMapi(): static
    {
        return $this->appendRules([
            'type' => 'required|string|max:32',
            'out_trade_no' => 'required|string|max:64',
            'notify_url' => 'required|string|max:255',
            'return_url' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'money' => 'required|regex:/^(?=.*[1-9])\d+(?:\.\d{1,2})?$/',
            'clientip' => 'required|ip',
            'sign_type' => 'required|string|in:MD5',
            'sign' => 'required|string|max:255',
        ]);
    }

    /**
     * 商户信息查询场景。
     *
     * @return static
     */
    public function sceneApiQuery(): static
    {
        return $this->appendRules([
            'key' => 'required|string|max:128',
        ]);
    }

    /**
     * 结算记录查询场景。
     *
     * @return static
     */
    public function sceneApiSettle(): static
    {
        return $this->appendRules([
            'key' => 'required|string|max:128',
        ]);
    }

    /**
     * 单个订单查询场景。
     *
     * @return static
     */
    public function sceneApiOrder(): static
    {
        return $this->appendRules([
            'key' => 'required|string|max:128',
            'trade_no' => 'nullable|string|max:64|required_without:out_trade_no',
            'out_trade_no' => 'nullable|string|max:64|required_without:trade_no',
        ]);
    }

    /**
     * 订单列表查询场景。
     *
     * @return static
     */
    public function sceneApiOrders(): static
    {
        return $this->appendRules([
            'key' => 'required|string|max:128',
            'page' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);
    }

    /**
     * 退款申请场景。
     *
     * @return static
     */
    public function sceneApiRefund(): static
    {
        return $this->appendRules([
            'key' => 'required|string|max:128',
            'money' => 'required|regex:/^(?=.*[1-9])\d+(?:\.\d{1,2})?$/',
            'trade_no' => 'nullable|string|max:64|required_without:out_trade_no',
            'out_trade_no' => 'nullable|string|max:64|required_without:trade_no',
        ]);
    }
}
