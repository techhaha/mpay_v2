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
        'current_password' => 'required|string|min:6|max:100',
        'password_confirm' => 'required|string|min:6|max:100|same:password',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'username' => '用户名',
        'password' => '密码',
        'current_password' => '旧密码',
        'password_confirm' => '确认密码',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'login' => ['username', 'password'],
        'changePassword' => ['current_password', 'password', 'password_confirm'],
    ];
}


