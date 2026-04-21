<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 参数校验异常。
 */
class ValidationException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message message
     * @param int|array $bizCodeOrData 业务CodeOr数据
     * @param array $data 数据
     * @return void
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





