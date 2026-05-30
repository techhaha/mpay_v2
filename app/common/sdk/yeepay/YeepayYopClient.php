<?php

declare(strict_types=1);

namespace app\common\sdk\yeepay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 易宝 YOP 轻量客户端。
 *
 * 迁移自彩虹 `YopClient`：YOP-RSA2048-SHA256 请求签名、表单请求和
 * 回调 `response` 解密验签。
 */
class YeepayYopClient
{
    private const VERSION = '3.1.14';
    private const SERVER_ROOT = 'https://openapi.yeepay.com/yop-center';
    private const YOP_PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6p0XWjscY+gsyqKRhw9MeLsEmhFdBRhT2emOck/F1Omw38ZWhJxh9kDfs5HzFJMrVozgU+SJFDONxs8UB0wMILKRmqfLcfClG9MyCNuJkkfm0HFQv1hRGdOvZPXj3Bckuwa7FrEXBRYUhK7vJ40afumspthmse6bs6mZxNn/mALZ2X07uznOrrc2rk41Y2HftduxZw6T4EmtWuN2x4CZ8gwSyPAW5ZzZJLQ6tZDojBK4GZTAGhnn3bg5bBsBlw2+FLkCQBuDsJVsFPiGh/b6K/+zGTvWyUcu+LUj2MejYQELDO3i2vQXVDk7lVi2/TcUYefvIcssnzsfCfjaorxsuwIDAQAB';

    /**
     * SDK 配置。
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private Client $httpClient;

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起 POST 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $params): array
    {
        $encodedParams = $this->encodedParams($params);
        $headers = $this->signedHeaders('POST', $path, $encodedParams);

        try {
            $response = $this->httpClient->post($this->serverRoot() . $path, [
                'headers' => $headers + [
                    'x-yop-sdk-langs' => 'php',
                    'x-yop-sdk-version' => self::VERSION,
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ],
                'body' => http_build_query($encodedParams),
            ]);
        } catch (GuzzleException $e) {
            throw new YeepaySdkException('易宝网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (is_array($payload) && isset($payload['result']) && is_array($payload['result'])) {
            return $payload['result'];
        }
        if (is_array($payload) && isset($payload['subMessage'])) {
            throw new YeepaySdkException('[' . (string) ($payload['subCode'] ?? '') . ']' . (string) $payload['subMessage']);
        }
        if (is_array($payload) && isset($payload['message'])) {
            throw new YeepaySdkException((string) $payload['message']);
        }

        throw new YeepaySdkException('易宝响应解析失败');
    }

    /**
     * 解密并验证通知 response。
     *
     * @return array<string, mixed>
     */
    public function notifyDecrypt(string $source): array
    {
        $args = explode('$', $source);
        if (count($args) !== 4) {
            throw new YeepaySdkException('易宝通知 response 格式错误');
        }

        [$encryptedRandomKey, $encryptedData, , $digestAlg] = $args;
        $randomKey = $this->rsaPrivateDecrypt($encryptedRandomKey);
        $plain = openssl_decrypt($this->base64UrlDecode($encryptedData), 'AES-128-ECB', $randomKey, OPENSSL_RAW_DATA);
        if (!is_string($plain) || $plain === '') {
            throw new YeepaySdkException('易宝通知数据解密失败');
        }

        $sign = substr(strrchr($plain, '$') ?: '', 1);
        $sourceData = substr($plain, 0, strlen($plain) - strlen($sign) - 1);
        if (!$this->rsaPublicVerify($sourceData, $sign, $digestAlg ?: 'SHA256')) {
            throw new YeepaySdkException('易宝通知验签失败');
        }

        $data = json_decode($sourceData, true);
        if (!is_array($data)) {
            throw new YeepaySdkException('易宝通知数据不是合法 JSON');
        }

        return $data;
    }

    /**
     * 构造 YOP 签名请求头。
     *
     * @param array<string, mixed> $params 已编码参数
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $path, array $params): array
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $headers = [
            'x-yop-appkey' => $this->configText('app_key'),
            'x-yop-request-id' => bin2hex(random_bytes(16)),
        ];
        $authString = 'yop-auth-v2/' . $this->configText('app_key') . '/' . $timestamp . '/1800';
        $canonicalRequest = $authString . "\n"
            . $method . "\n"
            . $path . "\n"
            . $this->canonicalQueryString($params) . "\n"
            . 'x-yop-request-id:' . $headers['x-yop-request-id'];
        $headers['Authorization'] = 'YOP-RSA2048-SHA256 ' . $authString . '/x-yop-request-id/' . $this->rsaPrivateSign($canonicalRequest);

        return $headers;
    }

    /**
     * 编码请求参数。
     *
     * @param array<string, mixed> $params 原始参数
     * @return array<string, mixed>
     */
    private function encodedParams(array $params): array
    {
        foreach ($params as $key => $value) {
            $params[$key] = rawurlencode((string) $value);
        }

        return $params;
    }

    /**
     * 构造规范查询串。
     *
     * @param array<string, mixed> $params 已编码参数
     */
    private function canonicalQueryString(array $params): string
    {
        ksort($params);
        $pieces = [];
        foreach ($params as $key => $value) {
            $pieces[] = $key . '=' . (string) $value;
        }

        return implode('&', $pieces);
    }

    /**
     * 商户私钥签名。
     */
    private function rsaPrivateSign(string $data, string $digestAlg = 'SHA256'): string
    {
        $privateKey = openssl_pkey_get_private($this->pemPrivateKey());
        if ($privateKey === false) {
            throw new YeepaySdkException('易宝商户私钥错误');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $privateKey, $digestAlg)) {
            throw new YeepaySdkException('易宝请求签名失败');
        }

        return $this->base64UrlEncode($signature) . '$SHA256';
    }

    /**
     * 易宝公钥验签。
     */
    private function rsaPublicVerify(string $data, string $sign, string $digestAlg): bool
    {
        $publicKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . wordwrap(self::YOP_PUBLIC_KEY, 64, "\n", true) . "\n-----END PUBLIC KEY-----");
        if ($publicKey === false) {
            throw new YeepaySdkException('易宝平台公钥错误');
        }

        return openssl_verify($data, $this->base64UrlDecode($sign), $publicKey, $digestAlg) === 1;
    }

    /**
     * 商户私钥解密。
     */
    private function rsaPrivateDecrypt(string $data): string
    {
        $privateKey = openssl_pkey_get_private($this->pemPrivateKey());
        if ($privateKey === false || !openssl_private_decrypt($this->base64UrlDecode($data), $decrypted, $privateKey)) {
            throw new YeepaySdkException('易宝通知随机密钥解密失败');
        }

        return $decrypted;
    }

    /**
     * 商户私钥 PEM。
     */
    private function pemPrivateKey(): string
    {
        $key = $this->configText('merchant_private_key');
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        return "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * URL 安全 Base64 编码。
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL 安全 Base64 解码。
     */
    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * YOP 网关地址。
     */
    private function serverRoot(): string
    {
        $custom = $this->configText('api_base_url');

        return $custom !== '' ? rtrim($custom, '/') : self::SERVER_ROOT;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
