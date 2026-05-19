<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

/**
 * 支付宝 RSA2 签名工具。
 *
 * 支付宝 OpenAPI 的请求加签和通知验签都基于同一套规则：
 * 1. 删除 sign 字段，通知验签时还需要删除 sign_type 字段。
 * 2. 按参数名 ASCII 升序排序。
 * 3. 使用 key=value&key=value 形式拼接待签名字符串。
 * 4. 使用 RSA2，即 SHA256withRSA，生成或验证 Base64 签名。
 */
class AlipaySigner
{
    /**
     * 构造支付宝待签名字符串。
     *
     * @param array<string, mixed> $params 参数数组
     * @param bool $excludeSignType 是否排除 sign_type；通知验签时必须排除
     * @return string 待签名字符串
     */
    public static function signContent(array $params, bool $excludeSignType = false): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || ($excludeSignType && $key === 'sign_type')) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $pairs[] = $key . '=' . (string) $value;
        }

        return implode('&', $pairs);
    }

    /**
     * 使用应用私钥生成 RSA2 签名。
     *
     * @param string $content 待签名字符串
     * @param string $privateKey 应用私钥，支持带 PEM 头尾或纯 Base64 内容
     * @return string Base64 签名
     */
    public static function sign(string $content, string $privateKey): string
    {
        $resource = openssl_pkey_get_private(self::normalizePrivateKey($privateKey));
        if ($resource === false) {
            throw new AlipaySdkException('支付宝应用私钥无效');
        }

        $signature = '';
        $success = openssl_sign($content, $signature, $resource, OPENSSL_ALGO_SHA256);
        if (!$success || $signature === '') {
            throw new AlipaySdkException('支付宝请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 使用支付宝公钥验证 RSA2 签名。
     *
     * @param string $content 待验签字符串
     * @param string $sign Base64 签名
     * @param string $publicKey 支付宝公钥，支持带 PEM 头尾或纯 Base64 内容
     * @return bool 是否验签通过
     */
    public static function verify(string $content, string $sign, string $publicKey): bool
    {
        $decoded = base64_decode(trim($sign), true);
        if ($decoded === false) {
            return false;
        }

        $resource = openssl_pkey_get_public(self::normalizePublicKey($publicKey));
        if ($resource === false) {
            throw new AlipaySdkException('支付宝公钥无效');
        }

        return openssl_verify($content, $decoded, $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 标准化应用私钥 PEM。
     *
     * @param string $privateKey 应用私钥
     * @return string PEM 私钥
     */
    public static function normalizePrivateKey(string $privateKey): string
    {
        return self::normalizePem($privateKey, 'PRIVATE KEY');
    }

    /**
     * 标准化支付宝公钥 PEM。
     *
     * @param string $publicKey 支付宝公钥
     * @return string PEM 公钥
     */
    public static function normalizePublicKey(string $publicKey): string
    {
        return self::normalizePem($publicKey, 'PUBLIC KEY');
    }

    /**
     * 给纯 Base64 密钥补齐 PEM 头尾。
     *
     * @param string $key 原始密钥
     * @param string $label PEM 标签
     * @return string PEM 内容
     */
    private static function normalizePem(string $key, string $label): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (str_contains($key, '-----BEGIN ')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key) ?? '';

        return "-----BEGIN {$label}-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END {$label}-----";
    }
}
