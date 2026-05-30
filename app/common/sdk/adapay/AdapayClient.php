<?php

declare(strict_types=1);

namespace app\common\sdk\adapay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * AdaPay 轻量客户端。
 */
class AdapayClient
{
    private const API_GATEWAY = 'https://api.adapay.tech';
    private const PAGE_GATEWAY = 'https://page.adapay.tech';

    /**
     * SDK 配置。
     *
     * @var array<string, string>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private Client $httpClient;

    /**
     * 构造方法。
     *
     * @param array<string, string> $config SDK 配置
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
     * 创建支付对象。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function createPayment(array $payload): array
    {
        return $this->request(self::API_GATEWAY, 'POST', '/v1/payments', $payload + [
            'app_id' => $this->config['app_id'],
            'sign_type' => 'RSA2',
        ]);
    }

    /**
     * 发起 AdaPay 页面产品请求。
     *
     * @param string $funcCode 产品方法
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function pageRequest(string $funcCode, array $payload): array
    {
        return $this->request(self::PAGE_GATEWAY, 'POST', '/v1/' . str_replace('.', '/', $funcCode), $payload + [
            'app_id' => $this->config['app_id'],
            'adapay_func_code' => $funcCode,
        ]);
    }

    /**
     * 查询支付对象。
     *
     * @return array<string, mixed>
     */
    public function queryPayment(string $paymentId): array
    {
        return $this->request(self::API_GATEWAY, 'GET', '/v1/payments/' . rawurlencode($paymentId), []);
    }

    /**
     * 创建退款对象。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function refund(string $paymentId, array $payload): array
    {
        return $this->request(self::API_GATEWAY, 'POST', '/v1/payments/' . rawurlencode($paymentId) . '/refunds', $payload);
    }

    /**
     * 校验回调签名。
     */
    public function verifyNotify(string $sign, string $data): bool
    {
        if ($sign === '' || $data === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->pemKey($this->config['platform_public_key'], 'public'));
        if ($publicKey === false) {
            throw new AdapaySdkException('AdaPay平台公钥不正确');
        }

        return openssl_verify($data, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * 发起 HTTP 请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    private function request(string $gateway, string $method, string $path, array $payload): array
    {
        $url = $gateway . $path;
        $signedData = $method === 'GET' ? http_build_query($payload) : $payload;
        $options = [
            'headers' => [
                'Authorization' => $this->config['api_key'],
                'Signature' => $this->signature($url, $signedData),
                'sdk_version' => 'v1.0.0',
            ],
        ];
        if ($method === 'GET') {
            $options['headers']['Content-Type'] = 'text/html';
            $options['query'] = $payload;
        } else {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $payload;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new AdapaySdkException('AdaPay网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new AdapaySdkException('AdaPay响应不是合法 JSON');
        }
        if (!isset($result['data'])) {
            throw new AdapaySdkException((string) ($result['message'] ?? 'AdaPay请求失败'));
        }

        $data = json_decode((string) $result['data'], true);
        if (!is_array($data)) {
            throw new AdapaySdkException('AdaPay业务响应不是合法 JSON');
        }
        if (!in_array((string) ($data['status'] ?? ''), ['succeeded', 'pending'], true) && empty($data['expend'])) {
            throw new AdapaySdkException('[' . (string) ($data['error_code'] ?? '') . ']' . (string) ($data['error_msg'] ?? 'AdaPay业务失败'));
        }

        return $data;
    }

    /**
     * 生成 AdaPay 请求签名。
     *
     * @param array<string, mixed>|string $payload 被签名参数
     */
    private function signature(string $url, array|string $payload): string
    {
        $content = $url . (is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $payload);
        $privateKey = openssl_pkey_get_private($this->pemKey($this->config['merchant_private_key'], 'private'));
        if ($privateKey === false) {
            throw new AdapaySdkException('AdaPay商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new AdapaySdkException('AdaPay请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 规范化 PEM 密钥。
     */
    private function pemKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $label = $type === 'private' ? 'PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$label}-----\n" . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true) . "\n-----END {$label}-----";
    }
}
