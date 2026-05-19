<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

/**
 * 微信支付签名、验签和回调解密工具。
 *
 * V3 使用商户 API 私钥做 SHA256-RSA 签名，签名结果放入 Authorization 请求头；
 * V2 使用商户 API 密钥做 MD5 或 HMAC-SHA256 签名，签名字段放在 XML 参数中。
 */
class WxpaySigner
{
    /**
     * 生成 V3 请求签名原文。
     *
     * @param string $method HTTP 方法
     * @param string $pathWithQuery 请求路径，包含 query string，不包含域名
     * @param string $timestamp Unix 时间戳字符串
     * @param string $nonceStr 随机串
     * @param string $body 请求体，GET 请求为空字符串
     * @return string 签名原文
     */
    public static function v3RequestContent(
        string $method,
        string $pathWithQuery,
        string $timestamp,
        string $nonceStr,
        string $body
    ): string {
        return strtoupper($method) . "\n"
            . $pathWithQuery . "\n"
            . $timestamp . "\n"
            . $nonceStr . "\n"
            . $body . "\n";
    }

    /**
     * 生成 V3 请求 Authorization 头。
     *
     * @param string $mchId 商户号；服务商模式下为服务商商户号
     * @param string $serialNo 商户 API 证书序列号
     * @param string $method HTTP 方法
     * @param string $pathWithQuery 请求路径，包含 query string，不包含域名
     * @param string $body 请求体
     * @param string $privateKey 商户 API 私钥内容
     * @param string|null $timestamp 指定时间戳，测试时可传入
     * @param string|null $nonceStr 指定随机串，测试时可传入
     * @return string Authorization 头内容
     */
    public static function v3Authorization(
        string $mchId,
        string $serialNo,
        string $method,
        string $pathWithQuery,
        string $body,
        string $privateKey,
        ?string $timestamp = null,
        ?string $nonceStr = null
    ): string {
        $timestamp = $timestamp ?: (string) time();
        $nonceStr = $nonceStr ?: self::nonceStr();
        $content = self::v3RequestContent($method, $pathWithQuery, $timestamp, $nonceStr, $body);
        $signature = self::rsaSign($content, $privateKey);

        $attributes = [
            'mchid' => $mchId,
            'nonce_str' => $nonceStr,
            'timestamp' => $timestamp,
            'serial_no' => $serialNo,
            'signature' => $signature,
        ];

        $items = [];
        foreach ($attributes as $key => $value) {
            $items[] = $key . '="' . addcslashes($value, '\\"') . '"';
        }

        return 'WECHATPAY2-SHA256-RSA2048 ' . implode(',', $items);
    }

    /**
     * 生成 JSAPI 或小程序调起支付签名。
     *
     * @param string $appId 公众号/小程序 AppID
     * @param string $timeStamp 时间戳字符串
     * @param string $nonceStr 随机串
     * @param string $package package 字段，格式为 prepay_id=xxx
     * @param string $privateKey 商户 API 私钥内容
     * @return string Base64 签名
     */
    public static function jsapiPaySign(
        string $appId,
        string $timeStamp,
        string $nonceStr,
        string $package,
        string $privateKey
    ): string {
        return self::rsaSign($appId . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $package . "\n", $privateKey);
    }

    /**
     * 生成 APP 调起支付签名。
     *
     * @param string $appId APP 应用 AppID
     * @param string $timeStamp 时间戳字符串
     * @param string $nonceStr 随机串
     * @param string $prepayId 预支付交易会话 ID，不带 prepay_id= 前缀
     * @param string $privateKey 商户 API 私钥内容
     * @return string Base64 签名
     */
    public static function appPaySign(
        string $appId,
        string $timeStamp,
        string $nonceStr,
        string $prepayId,
        string $privateKey
    ): string {
        return self::rsaSign($appId . "\n" . $timeStamp . "\n" . $nonceStr . "\n" . $prepayId . "\n", $privateKey);
    }

    /**
     * 使用商户 API 私钥生成 RSA-SHA256 签名。
     *
     * @param string $content 签名原文
     * @param string $privateKey 私钥内容，支持 PEM 或纯 Base64
     * @return string Base64 签名
     */
    public static function rsaSign(string $content, string $privateKey): string
    {
        $resource = openssl_pkey_get_private(self::normalizePrivateKey($privateKey));
        if ($resource === false) {
            throw new WxpaySdkException('微信支付商户 API 私钥无效');
        }

        $signature = '';
        $success = openssl_sign($content, $signature, $resource, OPENSSL_ALGO_SHA256);
        if (!$success || $signature === '') {
            throw new WxpaySdkException('微信支付 RSA 签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 验证 V3 平台证书或平台公钥签名。
     *
     * @param string $timestamp 响应头/通知头中的时间戳
     * @param string $nonce 响应头/通知头中的随机串
     * @param string $body 原始响应体或通知体
     * @param string $signature 响应头/通知头中的 Base64 签名
     * @param string $publicKeyOrCert 微信支付平台公钥或平台证书内容
     * @return bool 是否验签通过
     */
    public static function verifyV3(
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
        string $publicKeyOrCert
    ): bool {
        $decoded = base64_decode(trim($signature), true);
        if ($decoded === false) {
            return false;
        }

        $publicKey = str_contains($publicKeyOrCert, '-----BEGIN CERTIFICATE-----')
            ? self::publicKeyFromCertificate($publicKeyOrCert)
            : self::normalizePublicKey($publicKeyOrCert);
        $resource = openssl_pkey_get_public($publicKey);
        if ($resource === false) {
            throw new WxpaySdkException('微信支付平台公钥无效');
        }

        $content = $timestamp . "\n" . $nonce . "\n" . $body . "\n";

        return openssl_verify($content, $decoded, $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 生成 V2 参数签名。
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $apiKey 商户 API 密钥
     * @param string $signType MD5 或 HMAC-SHA256
     * @return string 大写签名
     */
    public static function signV2(array $params, string $apiKey, string $signType = 'HMAC-SHA256'): string
    {
        $content = self::v2SignContent($params, $apiKey);
        $signType = strtoupper($signType);

        if ($signType === 'MD5') {
            return strtoupper(md5($content));
        }
        if ($signType === 'HMAC-SHA256') {
            return strtoupper(hash_hmac('sha256', $content, $apiKey));
        }

        throw new WxpaySdkException('微信支付 V2 签名类型必须是 MD5 或 HMAC-SHA256');
    }

    /**
     * 验证 V2 响应或通知签名。
     *
     * @param array<string, mixed> $params 响应或通知参数
     * @param string $apiKey 商户 API 密钥
     * @return bool 是否验签通过
     */
    public static function verifyV2(array $params, string $apiKey): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        $signType = (string) ($params['sign_type'] ?? $params['signType'] ?? 'MD5');

        return hash_equals($sign, self::signV2($params, $apiKey, $signType));
    }

    /**
     * 生成 V2 待签名字符串。
     *
     * @param array<string, mixed> $params 参数
     * @param string $apiKey 商户 API 密钥
     * @return string 待签名字符串
     */
    public static function v2SignContent(array $params, string $apiKey): string
    {
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $pairs[] = $key . '=' . (string) $value;
        }

        return implode('&', $pairs) . '&key=' . $apiKey;
    }

    /**
     * 解密 V3 回调 resource。
     *
     * @param array<string, mixed> $resource 通知 resource 节点
     * @param string $apiV3Key APIv3 密钥，长度应为 32 字节
     * @return array<string, mixed> 解密后的 JSON 数据
     */
    public static function decryptResource(array $resource, string $apiV3Key): array
    {
        $ciphertext = (string) ($resource['ciphertext'] ?? '');
        $nonce = (string) ($resource['nonce'] ?? '');
        $associatedData = (string) ($resource['associated_data'] ?? '');
        if ($ciphertext === '' || $nonce === '') {
            throw new WxpaySdkException('微信支付通知 resource 缺少 ciphertext 或 nonce');
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) <= 16) {
            throw new WxpaySdkException('微信支付通知 ciphertext 无效');
        }

        $cipherRaw = substr($decoded, 0, -16);
        $tag = substr($decoded, -16);
        $plainText = openssl_decrypt(
            $cipherRaw,
            'aes-256-gcm',
            $apiV3Key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData
        );
        if ($plainText === false) {
            throw new WxpaySdkException('微信支付通知解密失败');
        }

        $data = json_decode($plainText, true);
        if (!is_array($data)) {
            throw new WxpaySdkException('微信支付通知解密结果不是有效 JSON');
        }

        return $data;
    }

    /**
     * 生成随机字符串。
     *
     * @param int $length 长度
     * @return string 随机字符串
     */
    public static function nonceStr(int $length = 32): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $bytes = random_bytes($length);
        $result = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[ord($bytes[$i]) % ($max + 1)];
        }

        return $result;
    }

    /**
     * 标准化私钥 PEM。
     *
     * @param string $privateKey 原始私钥
     * @return string PEM 私钥
     */
    public static function normalizePrivateKey(string $privateKey): string
    {
        return self::normalizePem($privateKey, 'PRIVATE KEY');
    }

    /**
     * 标准化公钥 PEM。
     *
     * @param string $publicKey 原始公钥
     * @return string PEM 公钥
     */
    public static function normalizePublicKey(string $publicKey): string
    {
        return self::normalizePem($publicKey, 'PUBLIC KEY');
    }

    /**
     * 从平台证书中提取公钥。
     *
     * @param string $certificate 证书内容
     * @return string PEM 公钥
     */
    public static function publicKeyFromCertificate(string $certificate): string
    {
        $cert = openssl_x509_read($certificate);
        if ($cert === false) {
            throw new WxpaySdkException('微信支付平台证书无效');
        }

        $resource = openssl_pkey_get_public($cert);
        if ($resource === false) {
            throw new WxpaySdkException('微信支付平台证书公钥提取失败');
        }

        $details = openssl_pkey_get_details($resource);
        $key = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($key === '') {
            throw new WxpaySdkException('微信支付平台证书公钥为空');
        }

        return $key;
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
