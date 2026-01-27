<?php

namespace app\exceptions;

/**
 * 未认证或登录过期
 */
class UnauthorizedException extends BusinessException
{
    public function __construct(string $message = '登录状态已过期', int $bizCode = 401, mixed $data = null)
    {
        parent::__construct($message, $bizCode, $data);
    }
}


