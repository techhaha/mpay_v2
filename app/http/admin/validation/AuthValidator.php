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
    protected array $rules = [
        'username' => 'required|string|min:1|max:32',
        'password' => 'required|string|min:6|max:100',
    ];

    protected array $attributes = [
        'username' => '用户名',
        'password' => '密码',
    ];

    protected array $scenes = [
        'login' => ['username', 'password'],
    ];
}
