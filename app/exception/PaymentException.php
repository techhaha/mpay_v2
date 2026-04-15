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
     * 构造函数，统一组装业务码与附加数据。
     */
    public function __construct(string $message = '支付渠道处理失败', int $bizCode = 40200, array $data = [])
    {
        parent::__construct($message, $bizCode);

        if (!empty($data)) {
            $this->data($data);
        }
    }
}
