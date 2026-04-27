<?php

declare(strict_types=1);

namespace app\service\payment\epay;

/**
 * ePay MD5 签名实现。
 */
class Md5Signer extends EpaySignerAbstract implements EpaySignerInterface
{
    /**
     * 生成 MD5 签名。
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $key 密钥
     * @return string 签名结果
     */
    public function sign(array $params, string $key): string
    {
        $content = $this->buildContent($params);
        return md5($content . $key);
    }

    /**
     * 验证 MD5 签名。
     *
     * @param array<string, mixed> $params 待验签参数
     * @param string $sign 签名值
     * @param string $key 密钥
     * @return bool 是否通过
     */
    public function verify(array $params, string $sign, string $key): bool
    {
        $expected = $this->sign($params, $key);
        return hash_equals(strtolower($expected), strtolower(trim($sign)));
    }
}
