<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 收银台请求验证器。
 *
 * 定义收银台上下文查询与确认支付场景规则。
 */
class CashierValidator extends Validator
{
    protected array $rules = [
        'biz_no' => 'required|string|max:32',
        'pay_no' => 'required|string|max:32',
        'type' => 'nullable|string|max:32',
        'token' => 'nullable|string|max:64',
        'resume_token' => 'nullable|string|max:64',
        'auth_code' => 'nullable|string|max:256',
        'alipay_auth_code' => 'nullable|string|max:256',
        'wx_login_code' => 'nullable|string|max:256',
        'mini_code' => 'nullable|string|max:256',
        'code' => 'nullable|string|max:256',
        'state' => 'nullable|string|max:64',
        'respCode' => 'nullable|string|max:32',
        'userAuthCode' => 'nullable|string|max:256',
    ];

    protected array $attributes = [
        'biz_no' => '业务单号',
        'pay_no' => '支付单号',
        'type' => '支付方式',
        'token' => '身份流程Token',
        'resume_token' => '身份流程Token',
        'auth_code' => '授权码',
        'alipay_auth_code' => '支付宝小程序授权码',
        'wx_login_code' => '微信小程序登录Code',
        'mini_code' => '小程序登录Code',
        'code' => '授权Code',
        'state' => '授权State',
        'respCode' => '银联授权响应码',
        'userAuthCode' => '银联用户授权码',
    ];

    protected array $scenes = [
        'context' => ['biz_no'],
        'confirm' => ['biz_no', 'type'],
        'pay_order' => ['pay_no'],
        'pay_order_status' => ['pay_no'],
        'identity_context' => ['token', 'resume_token'],
        'identity_resume' => [
            'token',
            'resume_token',
            'auth_code',
            'alipay_auth_code',
            'wx_login_code',
            'mini_code',
            'code',
        ],
        'identity_wechat_callback' => ['code', 'state'],
        'identity_alipay_callback' => ['auth_code', 'state'],
        'identity_unionpay_callback' => ['respCode', 'userAuthCode', 'state'],
    ];

    /**
     * 收银台上下文场景。
     *
     * @return static
     */
    public function sceneContext(): static
    {
        return $this->appendRules([
            'biz_no' => 'required|string|max:32',
        ]);
    }

    /**
     * 收银台确认场景。
     *
     * @return static
     */
    public function sceneConfirm(): static
    {
        return $this->appendRules([
            'biz_no' => 'required|string|max:32',
            'type' => 'required|string|max:32',
        ]);
    }

    /**
     * 支付页详情场景。
     *
     * @return static
     */
    public function scenePayOrder(): static
    {
        return $this->appendRules([
            'pay_no' => 'required|string|max:32',
        ]);
    }

    /**
     * 支付状态查询场景。
     *
     * @return static
     */
    public function scenePayOrderStatus(): static
    {
        return $this->appendRules([
            'pay_no' => 'required|string|max:32',
        ]);
    }

    /**
     * 身份流程续跑场景。
     *
     * @return static
     */
    public function sceneIdentityResume(): static
    {
        return $this->appendRules([
            'token' => 'nullable|string|max:64',
            'resume_token' => 'nullable|string|max:64',
        ]);
    }

    /**
     * 身份流程上下文场景。
     *
     * @return static
     */
    public function sceneIdentityContext(): static
    {
        return $this->appendRules([
            'token' => 'nullable|string|max:64',
            'resume_token' => 'nullable|string|max:64',
        ]);
    }

    /**
     * 微信网页授权回调场景。
     *
     * @return static
     */
    public function sceneIdentityWechatCallback(): static
    {
        return $this->appendRules([
            'code' => 'nullable|string|max:256',
            'state' => 'required|string|max:64',
        ]);
    }

    /**
     * 支付宝生活号网页授权回调场景。
     *
     * @return static
     */
    public function sceneIdentityAlipayCallback(): static
    {
        return $this->appendRules([
            'auth_code' => 'nullable|string|max:256',
            'state' => 'required|string|max:64',
        ]);
    }

    /**
     * 云闪付 userAuth 回调场景。
     *
     * @return static
     */
    public function sceneIdentityUnionpayCallback(): static
    {
        return $this->appendRules([
            'respCode' => 'nullable|string|max:32',
            'userAuthCode' => 'nullable|string|max:256',
            'state' => 'required|string|max:64',
        ]);
    }
}
