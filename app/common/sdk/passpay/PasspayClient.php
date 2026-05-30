<?php

declare(strict_types=1);

namespace app\common\sdk\passpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 精秀支付 RSA2 网关客户端。
 */
class PasspayClient
{
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
     * 发起接口请求。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $method, array $bizContent): array
    {
        $payload = [
            'mchid' => $this->config['mch_id'],
            'method' => $method,
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => time(),
            'version' => '1.0',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post(rtrim($this->config['api_url'], '/') . '/' . $method, [
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new PasspaySdkException('精秀支付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new PasspaySdkException('精秀支付响应不是合法 JSON');
        }
        if ((int) ($data['code'] ?? 0) !== 1) {
            throw new PasspaySdkException((string) ($data['msg'] ?? '精秀支付请求失败'));
        }

        return (array) ($data['data'] ?? []);
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        return openssl_verify($this->signContent($payload), base64_decode($sign), $this->formatPublicKey($this->config['platform_public_key']), OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 请求参数签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        if (!openssl_sign($this->signContent($payload), $signature, $this->formatPrivateKey($this->config['merchant_private_key']), OPENSSL_ALGO_SHA256)) {
            throw new PasspaySdkException('精秀支付请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 待签名字符串。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function signContent(array $payload): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'sign' && $value !== '' && $value !== null) {
                $pieces[] = $key . '=' . (string) $value;
            }
        }

        return implode('&', $pieces);
    }

    /**
     * PEM 格式化商户私钥。
     */
    private function formatPrivateKey(string $key): string
    {
        return str_contains($key, 'BEGIN') ? $key : "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * PEM 格式化平台公钥。
     */
    private function formatPublicKey(string $key): string
    {
        return str_contains($key, 'BEGIN') ? $key : "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }
}
