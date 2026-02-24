<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;

/**
 * 系统内部错误异常
 * 用于配置文件错误、系统错误等不可预期的错误
 * 
 * 示例：
 * throw new InternalServerException('字典配置文件不存在');
 * throw new InternalServerException('配置文件格式错误：' . json_last_error_msg());
 * throw new InternalServerException('保存失败');
 */
class InternalServerException extends BusinessException
{
    public function __construct(string $message = '系统内部错误', int $bizCode = 500, array $data = [])
    {
        parent::__construct($message, $bizCode);
        if (!empty($data)) {
            $this->data($data);
        }
    }
}

