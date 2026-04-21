<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 业务状态异常。
 *
 * 用于状态不允许、资源已禁用、资源不可用和不支持等场景，统一业务码为 40910。
 */
class BusinessStateException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message message
     * @param array $data 数据
     * @param int $bizCode 业务Code
     * @return void
     */
    public function __construct(string $message = '业务状态不允许当前操作', array $data = [], int $bizCode = 40910)
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}





