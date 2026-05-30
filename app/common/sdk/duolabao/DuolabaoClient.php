<?php

declare(strict_types=1);

namespace app\common\sdk\duolabao;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 哆啦宝开放平台轻量客户端。
 *
 * 迁移彩虹 `duolabao` 插件的 JSON 请求、SHA1 令牌和回调验签主链路。
 */
class DuolabaoClient
{
    private const GATEWAY = 'https://openapi.duolabao.com';

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
     * 发起开放平台 JSON 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new DuolabaoSdkException('哆啦宝请求参数编码失败');
        }

        $timestamp = (string) time();
        try {
            $response = $this->httpClient->post(self::GATEWAY . $path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'accessKey' => $this->config['access_key'],
                    'timestamp' => $timestamp,
                    'token' => $this->token($timestamp, $path, $body),
                ],
                'body' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw new DuolabaoSdkException('哆啦宝网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new DuolabaoSdkException('哆啦宝响应不是合法 JSON');
        }
        if (($data['success'] ?? $data['result'] ?? false) !== true) {
            throw new DuolabaoSdkException((string) ($data['errorMsg'] ?? $data['message'] ?? '哆啦宝请求失败'));
        }

        return $data;
    }

    /**
     * 校验哆啦宝异步通知签名。
     */
    public function verifyNotify(string $body, string $timestamp, string $token): bool
    {
        if ($body === '' || $timestamp === '' || $token === '') {
            return false;
        }

        return hash_equals($this->token($timestamp, '', $body), strtoupper($token));
    }

    /**
     * 生成请求令牌。
     */
    private function token(string $timestamp, string $path = '', string $body = ''): string
    {
        $pieces = [
            'secretKey=' . $this->config['secret_key'],
            'timestamp=' . $timestamp,
        ];
        if ($path !== '') {
            $pieces[] = 'path=' . $path;
        }
        if ($body !== '') {
            $pieces[] = 'body=' . $body;
        }

        return strtoupper(sha1(implode('&', $pieces)));
    }
}
