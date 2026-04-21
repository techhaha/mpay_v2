<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 支付插件异常。
 *
 * 用于第三方渠道下单、查单、退款、验签和插件装配失败等场景。
 */
class PaymentException extends BusinessException
{
    /**
     * 构造方法。
     *
     * @param string $message message
     * @param int $bizCode 业务Code
     * @param array $data 数据
     * @return void
     */
    public function __construct(string $message = '支付渠道处理失败', int $bizCode = 40200, array $data = [])
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}





