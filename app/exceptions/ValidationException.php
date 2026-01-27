<?php

namespace app\exceptions;

/**
 * 参数校验异常
 */
class ValidationException extends BusinessException
{
    public function __construct(string $message = '参数校验失败', int $bizCode = 422, mixed $data = null)
    {
        parent::__construct($message, $bizCode, $data);
    }
}


