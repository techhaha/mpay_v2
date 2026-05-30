<?php

declare(strict_types=1);

namespace app\common\sdk\hlpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 汇联支付开放平台客户端。
 */
class HlpayClient
{
    private const GATEWAY = 'https://api.huilianlink.com';

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
     * 发起 JSON 接口请求。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $bizContent): array
    {
        $payload = [
            'appId' => $this->config['app_id'],
            'subSn' => $this->config['sub_sn'],
            'timestamp' => (string) time(),
            'requestId' => (string) (int) (microtime(true) * 1000),
            'version' => '1.01',
            'signType' => 'RSA2',
            'bizContent' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post(self::GATEWAY . $path, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new HlpaySdkException('汇联支付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new HlpaySdkException('汇联支付响应不是合法 JSON');
        }
        if ((int) ($data['code'] ?? 0) !== 200) {
            throw new HlpaySdkException((string) ($data['msg'] ?? '汇联支付请求失败'));
        }
        if (!$this->verify($data)) {
            throw new HlpaySdkException('汇联支付响应验签失败');
        }

        return (array) ($data['data'] ?? []);
    }

    /**
     * 校验响应或通知签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        if (isset($payload['data'])) {
            $payload['data'] = $this->normalizeDataField($payload['data']);
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
            throw new HlpaySdkException('汇联支付请求签名失败');
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
     * 规范化签名中的 data 字段。
     */
    private function normalizeDataField(mixed $data): string
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = json_last_error() === JSON_ERROR_NONE ? $decoded : $data;
        }
        if (!is_array($data)) {
            return (string) $data;
        }

        $data = $this->sortArray($data);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 递归排序数组。
     *
     * @param array<string, mixed> $data 原始数组
     * @return array<string, mixed>
     */
    private function sortArray(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortArray($value);
            }
        }

        return $data;
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
