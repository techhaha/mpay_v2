<?php

declare(strict_types=1);

namespace app\common\sdk\chinaums;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 银联商务开放平台轻量客户端。
 *
 * 迁移自彩虹 `ChinaumsBuild`：支持 OPEN-BODY-SIG JSON 请求、
 * OPEN-FORM-PARAM 跳转地址生成，以及回调 MD5/SHA256 验签。
 */
class ChinaumsClient
{
    private const PROD_GATEWAY = 'https://api-mop.chinaums.com';
    private const TEST_GATEWAY = 'https://test-api-open.chinaums.com';

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
     * 发起 JSON API 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function request(string $path, array $params): array
    {
        $time = time();
        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new ChinaumsSdkException('银联商务请求报文编码失败');
        }

        try {
            $response = $this->httpClient->post($this->gatewayUrl() . $path, [
                'headers' => [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Authorization' => $this->authorization($body, $time),
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new ChinaumsSdkException('银联商务网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new ChinaumsSdkException('银联商务响应不是合法 JSON');
        }

        return $data;
    }

    /**
     * 生成 OPEN-FORM-PARAM 跳转地址。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params 业务参数
     */
    public function formUrl(string $path, array $params): string
    {
        $time = time();
        $content = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($content)) {
            throw new ChinaumsSdkException('银联商务跳转参数编码失败');
        }

        $timestamp = date('YmdHis', $time);
        $nonce = md5(uniqid((string) mt_rand(), true));
        $query = [
            'authorization' => 'OPEN-FORM-PARAM',
            'appId' => $this->configText('app_id'),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'content' => $content,
        ];
        $query['signature'] = $this->signature($timestamp, $nonce, $content);

        return $this->gatewayUrl() . $path . '?' . http_build_query($query);
    }

    /**
     * 验证银联商务回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        $signType = (string) ($payload['signType'] ?? '');
        if ($sign === '') {
            return false;
        }

        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            $pieces[] = $key . '=' . (string) $value;
        }
        $content = implode('&', $pieces) . $this->configText('communication_key');
        $actual = strtoupper($signType === 'SHA256' ? hash('sha256', $content) : md5($content));

        return hash_equals($actual, $sign);
    }

    /**
     * 生成 OPEN-BODY-SIG 请求头。
     */
    private function authorization(string $body, int $time): string
    {
        $timestamp = date('YmdHis', $time);
        $nonce = md5(uniqid((string) mt_rand(), true));

        return sprintf(
            'OPEN-BODY-SIG AppId="%s", Timestamp="%s", Nonce="%s", Signature="%s"',
            $this->configText('app_id'),
            $timestamp,
            $nonce,
            $this->signature($timestamp, $nonce, $body)
        );
    }

    /**
     * 生成开放平台 HMAC-SHA256 签名。
     */
    private function signature(string $timestamp, string $nonce, string $body): string
    {
        $content = $this->configText('app_id') . $timestamp . $nonce . hash('sha256', $body);

        return base64_encode(hash_hmac('sha256', $content, $this->configText('app_key'), true));
    }

    /**
     * 获取网关地址。
     */
    private function gatewayUrl(): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return (bool) ($this->config['sandbox'] ?? false) ? self::TEST_GATEWAY : self::PROD_GATEWAY;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
