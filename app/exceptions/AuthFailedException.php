<?php

namespace app\exceptions;

/**
 * 认证失败（账号或密码错误）
 */
class AuthFailedException extends BusinessException
{
    public function __construct(string $message = '账号或者密码错误', int $bizCode = 400, mixed $data = null)
    {
        parent::__construct($message, $bizCode, $data);
    }
}


