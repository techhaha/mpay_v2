<?php

declare(strict_types=1);

namespace app\common\sdk\huifu;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 汇付斗拱平台轻量客户端。
 *
 * 迁移自彩虹 `HuifuClient`：请求体为 `sys_id/product_id/data/sign`，
 * `data` 按 key 排序后用 RSA-SHA256 签名，响应和通知用汇付公钥验签。
 */
class HuifuClient
{
    private const DEFAULT_GATEWAY = 'https://api.huifu.com';

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
     * 请求汇付 API。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $data 业务参数
     * @return array<string, mixed>
     */
    public function request(string $path, array $data): array
    {
        $body = [
            'sys_id' => $this->configText('sys_id'),
            'product_id' => $this->configText('product_id'),
            'data' => $data,
        ];
        $body['sign'] = $this->sign($data);

        try {
            $response = $this->httpClient->post($this->gatewayUrl() . $path, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new HuifuSdkException('汇付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload) || !is_array($payload['data'] ?? null) || (string) ($payload['sign'] ?? '') === '') {
            throw new HuifuSdkException('汇付响应解析失败');
        }

        if (!$this->verifyData($payload['data'], (string) $payload['sign'])) {
            throw new HuifuSdkException('汇付响应验签失败');
        }

        return $payload['data'];
    }

    /**
     * 验证异步通知签名。
     */
    public function verifyNotify(string $data, string $sign): bool
    {
        return $data !== '' && $sign !== '' && $this->verify($data, $sign);
    }

    /**
     * 生成请求签名。
     *
     * @param array<string, mixed> $data 业务参数
     */
    private function sign(array $data): string
    {
        $privateKey = openssl_pkey_get_private($this->pemKey($this->configText('merchant_private_key'), 'private'));
        if ($privateKey === false) {
            throw new HuifuSdkException('汇付商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($this->signContent($data), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new HuifuSdkException('汇付请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 验证响应 data 签名。
     *
     * @param array<string, mixed> $data 响应业务数据
     */
    private function verifyData(array $data, string $sign): bool
    {
        return $this->verify($this->signContent($data), $sign);
    }

    /**
     * RSA-SHA256 验签。
     */
    private function verify(string $content, string $sign): bool
    {
        $publicKey = openssl_pkey_get_public($this->pemKey($this->configText('huifu_public_key'), 'public'));
        if ($publicKey === false) {
            throw new HuifuSdkException('汇付公钥不正确');
        }

        return openssl_verify($content, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 构造待签名 JSON。
     *
     * @param array<string, mixed> $data 业务参数
     */
    private function signContent(array $data): string
    {
        $data = array_filter($data, static fn ($value): bool => $value !== null);
        ksort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * 规范化 PEM 密钥。
     */
    private function pemKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $header = $type === 'private' ? 'PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$header}-----\n"
            . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true)
            . "\n-----END {$header}-----";
    }

    /**
     * 获取网关地址。
     */
    private function gatewayUrl(): string
    {
        $gateway = $this->configText('api_base_url');

        return $gateway !== '' ? rtrim($gateway, '/') : self::DEFAULT_GATEWAY;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
