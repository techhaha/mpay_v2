<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 参数校验异常。
 */
class ValidationException extends BusinessException
{
    /**
     * 构造函数，组装异常信息。
     */
    public function __construct(string $message = '参数校验失败', int|array $bizCodeOrData = 40001, array $data = [])
    {
        if (is_array($bizCodeOrData)) {
            $data = $bizCodeOrData;
            $bizCode = 40001;
        } else {
            $bizCode = $bizCodeOrData;
        }

        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}
