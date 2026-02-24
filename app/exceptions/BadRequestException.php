<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;

/**
 * 请求参数错误异常
 * 用于请求参数格式错误、验证码错误等情况
 * 
 * 示例：
 * throw new BadRequestException('验证码错误或已失效');
 * throw new BadRequestException('请求参数格式错误');
 */
class BadRequestException extends BusinessException
{
    public function __construct(string $message = '请求参数错误', int $bizCode = 400, array $data = [])
    {
        parent::__construct($message, $bizCode);
        if (!empty($data)) {
            $this->data($data);
        }
    }
}

