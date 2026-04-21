<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 管理员登录参数校验器。
 *
 * 用于校验后台管理员登录入参。
 */
class AuthValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'username' => 'required|string|min:1|max:32',
        'password' => 'required|string|min:6|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'username' => '用户名',
        'password' => '密码',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'login' => ['username', 'password'],
    ];
}


