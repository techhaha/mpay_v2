<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 商户登录参数校验器。
 */
class AuthValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'merchant_no' => 'required|string|min:1|max:32',
        'password' => 'required|string|min:6|max:32',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'merchant_no' => '商户号',
        'password' => '登录密码',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'login' => ['merchant_no', 'password'],
    ];
}


