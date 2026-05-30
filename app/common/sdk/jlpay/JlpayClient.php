<?php

declare(strict_types=1);

namespace app\common\sdk\jlpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 嘉联支付开放平台国密客户端。
 */
class JlpayClient
{
    private const GATEWAY = 'https://openapi.jlpay.com';
    private const TEST_GATEWAY = 'https://openapi-uat.jlpay.com';

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
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => false,
        ]);
    }

    /**
     * 发起 JSON 接口请求。
     *
     * @param array<string, mixed> $data 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $data): array
    {
        $this->ensureCryptoAvailable();
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sign = $this->sign('POST', $path, $timestamp, $nonce, $body);

        try {
            $response = $this->httpClient->post($this->gateway() . $path, [
                'headers' => [
                    'Accept' => 'application/json; charset=utf-8',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'x-jlpay-appid' => $this->config['app_id'],
                    'x-jlpay-nonce' => $nonce,
                    'x-jlpay-timestamp' => $timestamp,
                    'x-jlpay-sign-alg' => 'SM3WithSM2WithDer',
                    'x-jlpay-sign' => $sign,
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new JlpaySdkException('嘉联支付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $content = (string) $response->getBody();
        $result = json_decode($content, true);
        if (!is_array($result)) {
            throw new JlpaySdkException('嘉联支付响应不是合法 JSON');
        }
        if (!in_array((string) ($result['ret_code'] ?? ''), ['00', '00000'], true)) {
            throw new JlpaySdkException((string) ($result['ret_msg'] ?? '嘉联支付请求失败'));
        }
        if ($response->hasHeader('x-jlpay-sign') && !$this->verifyResponse('POST', $path, $content, $response->getHeaders())) {
            throw new JlpaySdkException('嘉联支付响应验签失败');
        }

        return $result;
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, string> $headers 请求头
     */
    public function verifyNotify(string $path, string $body, array $headers): bool
    {
        $timestamp = $headers['x-jlpay-timestamp'][0] ?? $headers['X-Jlpay-Timestamp'][0] ?? '';
        $nonce = $headers['x-jlpay-nonce'][0] ?? $headers['X-Jlpay-Nonce'][0] ?? '';
        $sign = $headers['x-jlpay-sign'][0] ?? $headers['X-Jlpay-Sign'][0] ?? '';
        if ($timestamp === '' || $nonce === '' || $sign === '') {
            return false;
        }

        return $this->verify('POST', $path, $timestamp, $nonce, $body, $sign);
    }

    /**
     * 请求签名。
     */
    private function sign(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        $document = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $sm2 = new \Rtgm\sm\RtSm2('base64');
        return str_replace(PHP_EOL, '', $sm2->doSign($document, $this->config['merchant_private_key']));
    }

    /**
     * 验证签名。
     */
    private function verify(string $method, string $path, string $timestamp, string $nonce, string $body, string $sign): bool
    {
        $document = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $sm2 = new \Rtgm\sm\RtSm2('base64');
        return (bool) $sm2->verifySign($document, $sign, $this->config['platform_public_key']);
    }

    /**
     * 验证响应签名。
     *
     * @param array<string, array<int, string>> $headers 响应头
     */
    private function verifyResponse(string $method, string $path, string $body, array $headers): bool
    {
        $timestamp = $headers['x-jlpay-timestamp'][0] ?? $headers['X-Jlpay-Timestamp'][0] ?? '';
        $nonce = $headers['x-jlpay-nonce'][0] ?? $headers['X-Jlpay-Nonce'][0] ?? '';
        $sign = $headers['x-jlpay-sign'][0] ?? $headers['X-Jlpay-Sign'][0] ?? '';
        if ($timestamp === '' || $nonce === '' || $sign === '') {
            return false;
        }

        return $this->verify($method, $path, $timestamp, $nonce, $body, $sign);
    }

    /**
     * 检查国密依赖。
     */
    private function ensureCryptoAvailable(): void
    {
        if (!extension_loaded('gmp') || !class_exists(\Rtgm\sm\RtSm2::class)) {
            throw new JlpaySdkException('嘉联支付国密接口需要 GMP 扩展和 Rtgm\\sm 国密库');
        }
    }

    /**
     * 当前网关地址。
     */
    private function gateway(): string
    {
        return $this->config['is_test'] === '1' ? self::TEST_GATEWAY : self::GATEWAY;
    }
}
