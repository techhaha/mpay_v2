<?php

namespace app\exception;

use Webman\Exception\BusinessException;

/**
 * 商户余额不足异常。
 */
class BalanceInsufficientException extends BusinessException
{
    /**
     * 构造函数，组装异常信息。
     */
    public function __construct(int $merchantId = 0, int $needAmount = 0, int $availableAmount = 0, array $data = [])
    {
        parent::__construct('余额不足', 40011);

        $payload = array_filter([
            'merchant_id' => $merchantId ?: null,
            'need_amount' => $needAmount ?: null,
            'available_amount' => $availableAmount ?: null,
        ], static fn ($value) => $value !== null && $value !== '');

        if (!empty($data)) {
            $payload = array_merge($payload, $data);
        }

        if (!empty($payload)) {
            $this->data($payload);
        }
    }
}
