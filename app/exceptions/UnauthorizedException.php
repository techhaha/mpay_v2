<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;

/**
 * 未授权异常
 * 用于认证失败、token无效等情况
 * 
 * 示例：
 * throw new UnauthorizedException('账号或密码错误');
 * throw new UnauthorizedException('认证令牌已过期');
 * throw new UnauthorizedException('认证令牌无效');
 */
class UnauthorizedException extends BusinessException
{
    public function __construct(string $message = '未授权', int $bizCode = 401, array $data = [])
    {
        parent::__construct($message, $bizCode);
        if (!empty($data)) {
            $this->data($data);
        }
    }
}

