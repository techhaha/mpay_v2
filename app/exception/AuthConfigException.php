<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 认证配置异常。
 *
 * 用于认证域不存在、JWT 密钥长度不足和认证配置不合法等场景。
 */
class AuthConfigException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message message
     * @param array $data 数据
     * @param int $bizCode 业务Code
     * @return void
     */
    public function __construct(string $message = '认证配置错误', array $data = [], int $bizCode = 50001)
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}
