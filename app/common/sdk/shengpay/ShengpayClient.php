<?php

declare(strict_types=1);

namespace app\common\sdk\shengpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 盛付通商户 API 客户端。
 */
class ShengpayClient
{
    private const GATEWAY = 'https://mchapi.shengpay.com';

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
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $payload): array
    {
        $payload['mchId'] = $this->config['mch_id'];
        $payload['nonceStr'] = bin2hex(random_bytes(16));
        $payload['signType'] = 'RSA';
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post(self::GATEWAY . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new ShengpaySdkException('盛付通网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new ShengpaySdkException('盛付通响应不是合法 JSON');
        }
        if ((string) ($data['returnCode'] ?? '') !== 'SUCCESS') {
            throw new ShengpaySdkException((string) ($data['returnMsg'] ?? '盛付通请求失败'));
        }
        if ((string) ($data['resultCode'] ?? '') !== 'SUCCESS') {
            throw new ShengpaySdkException('[' . (string) ($data['errorCode'] ?? '') . ']' . (string) ($data['errorCodeDes'] ?? '盛付通业务失败'));
        }
        if (isset($data['sign']) && !$this->verify($data)) {
            throw new ShengpaySdkException('盛付通响应验签失败');
        }

        return $data;
    }

    /**
     * 校验签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        return openssl_verify($this->signContent($payload), base64_decode($sign), $this->formatPublicKey($this->config['platform_public_key'])) === 1;
    }

    /**
     * 请求参数签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        if (!openssl_sign($this->signContent($payload), $signature, $this->formatPrivateKey($this->config['merchant_private_key']))) {
            throw new ShengpaySdkException('盛付通请求签名失败');
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
