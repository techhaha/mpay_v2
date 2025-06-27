<?php

namespace app\http\admin\validate;

use Respect\Validation\Validator as v;

class UserValidator
{
    /**
     * 验证用户数据
     *
     * @param array $data 包含用户信息的数组，应包含 'username', 'email', 'password' 键
     * @return array 包含验证错误信息的数组，若验证通过则返回空数组
     */
    public function validate(array $data)
    {
        $errors = [];

        // 验证用户名
        $usernameValidator = v::stringType()->length(3, 20)->alnum();
        if (!$usernameValidator->validate($data['username'] ?? '')) {
            $errors['username'] = '用户名必须为 3 到 20 个字母或数字字符';
        }

        // 验证邮箱
        $emailValidator = v::email();
        if (!$emailValidator->validate($data['email'] ?? '')) {
            $errors['email'] = '请输入有效的邮箱地址';
        }

        // 验证密码
        $passwordValidator = v::stringType()->length(6, null);
        if (!$passwordValidator->validate($data['password'] ?? '')) {
            $errors['password'] = '密码至少需要 6 个字符';
        }

        return $errors;
    }
}
