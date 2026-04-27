<?php

declare(strict_types=1);

namespace app\service\payment\epay;

/**
 * ePay 签名器契约。
 */
interface EpaySignerInterface
{
    /**
     * 生成签名。
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $key 密钥
     * @return string 签名结果
     */
    public function sign(array $params, string $key): string;

    /**
     * 验证签名。
     *
     * @param array<string, mixed> $params 待验签参数
     * @param string $sign 签名值
     * @param string $key 密钥
     * @return bool 是否通过
     */
    public function verify(array $params, string $sign, string $key): bool;
}
