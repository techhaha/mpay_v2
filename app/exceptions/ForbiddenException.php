<?php

namespace app\exceptions;

/**
 * 权限不足
 */
class ForbiddenException extends BusinessException
{
    public function __construct(string $message = '无访问权限', int $bizCode = 403, mixed $data = null)
    {
        parent::__construct($message, $bizCode, $data);
    }
}


