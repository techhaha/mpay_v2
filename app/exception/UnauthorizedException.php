<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 鉴权异常。
 *
 * 用于 token 缺失、token 无效和登录态失效等场景，统一业务码为 40100。
 */
class UnauthorizedException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message message
     * @param array $data 数据
     * @param int $bizCode 业务Code
     * @return void
     */
    public function __construct(string $message = '未授权', array $data = [], int $bizCode = 401)
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}
