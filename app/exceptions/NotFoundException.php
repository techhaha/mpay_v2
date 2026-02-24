<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;

/**
 * 资源不存在异常
 * 用于资源未找到的情况
 * 
 * 示例：
 * throw new NotFoundException('用户不存在');
 * throw new NotFoundException('未找到指定的字典：' . $code);
 */
class NotFoundException extends BusinessException
{
    public function __construct(string $message = '资源不存在', int $bizCode = 404, array $data = [])
    {
        parent::__construct($message, $bizCode);
        if (!empty($data)) {
            $this->data($data);
        }
    }
}

