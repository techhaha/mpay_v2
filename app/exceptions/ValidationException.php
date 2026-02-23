<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 参数校验异常
 * 最常用的异常类型，用于参数验证、业务规则验证等
 * 
 * 示例：
 * throw new ValidationException('优惠券和会员不可叠加使用');
 * throw new ValidationException('手机号格式不正确');
 * throw new ValidationException('金额必须大于0');
 */
class ValidationException extends BusinessException
{
    public function __construct(string $message = '参数校验失败', int $bizCode = 422, array $data = [])
    {
        parent::__construct($message, $bizCode, $data);
    }
}
