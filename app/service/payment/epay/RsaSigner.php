<?php

declare(strict_types=1);

namespace app\service\payment\epay;

use app\exception\PaymentException;

/**
 * ePay RSA 签名实现。
 */
class RsaSigner extends EpaySignerAbstract implements EpaySignerInterface
{
    /**
     * 生成 RSA 签名。
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $key 私钥
     * @return string 签名结果
     */
    public function sign(array $params, string $key): string
    {
        $content = $this->buildContent($params);
        $privateKey = $this->normalizePem($key, 'PRIVATE');
        if ($privateKey === '') {
            throw new PaymentException('RSA 私钥不能为空', 40200);
        }

        $resource = openssl_pkey_get_private($privateKey);
        if ($resource === false) {
            throw new PaymentException('签名失败，RSA 私钥无效', 40200);
        }

        $result = openssl_sign($content, $signature, $resource, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new PaymentException('RSA 签名失败', 40200);
        }

        return base64_encode($signature);
    }

    /**
     * 验证 RSA 签名。
     *
     * @param array<string, mixed> $params 待验签参数
     * @param string $sign 签名值
     * @param string $key 公钥
     * @return bool 是否通过
     */
    public function verify(array $params, string $sign, string $key): bool
    {
        $content = $this->buildContent($params);
        $publicKey = $this->normalizePem($key, 'PUBLIC');
        if ($publicKey === '') {
            return false;
        }

        $resource = openssl_pkey_get_public($publicKey);
        if ($resource === false) {
            return false;
        }

        $result = openssl_verify($content, base64_decode(trim($sign), true) ?: '', $resource, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }
}
