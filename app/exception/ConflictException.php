<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 冲突异常。
 *
 * 用于幂等冲突、重复请求和重复提交等场景，统一业务码为 40900。
 */
class ConflictException extends BusinessException
{
    /**
     * 构造函数，组装异常信息。
     */
    public function __construct(string $message = '业务冲突', array $data = [], int $bizCode = 40900)
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}
