<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 资源不存在异常。
 *
 * 作为所有“找不到”类业务异常的统一基类，统一业务码为 40400。
 */
class ResourceNotFoundException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message message
     * @param array $data 数据
     * @param int $bizCode 业务Code
     * @return void
     */
    public function __construct(string $message = '资源不存在', array $data = [], int $bizCode = 40400)
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}





