<?php

namespace app\exceptions;

use Webman\Exception\BusinessException;

/**
 * 支付业务异常
 *
 * 用于支付相关业务错误，如：下单失败、退款失败、验签失败、渠道异常等。
 *
 * 示例：
 * throw new PaymentException('当前环境无可用支付产品');
 * throw new PaymentException('渠道返回错误', 402, ['channel_code' => 'lakala']);
 */
class PaymentException extends BusinessException
{
    public function __construct(string $message = '支付业务异常', int $bizCode = 402, array $data = [])
    {
        parent::__construct($message, $bizCode);
        if (!empty($data)) {
            $this->data($data);
        }
    }
}
