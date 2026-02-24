<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;

/**
 * 禁止访问异常
 * 用于无权限、账号被禁用等情况
 * 
 * 示例：
 * throw new ForbiddenException('账号已被禁用');
 * throw new ForbiddenException('无权限访问该资源');
 */
class ForbiddenException extends BusinessException
{
    public function __construct(string $message = '禁止访问', int $bizCode = 403, array $data = [])
    {
        parent::__construct($message, $bizCode);
        if (!empty($data)) {
            $this->data($data);
        }
    }
}

