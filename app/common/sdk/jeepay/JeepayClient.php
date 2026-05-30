<?php

declare(strict_types=1);

namespace app\common\sdk\jeepay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Jeepay 聚合支付轻量客户端。
 */
class JeepayClient
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
     * 发起 Jeepay JSON 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post(rtrim($this->config['api_url'], '/') . '/' . ltrim($path, '/'), [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new JeepaySdkException('Jeepay网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new JeepaySdkException('Jeepay响应不是合法 JSON');
        }
        if ((string) ($data['code'] ?? '') !== '0') {
            throw new JeepaySdkException((string) ($data['msg'] ?? $data['errMsg'] ?? 'Jeepay请求失败'));
        }

        return (array) ($data['data'] ?? $data);
    }

    /**
     * 校验 Jeepay 回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        return hash_equals($this->sign($payload), strtoupper($sign));
    }

    /**
     * 生成 MD5 签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            $pieces[] = $key . '=' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value);
        }

        return strtoupper(md5(implode('&', $pieces) . '&key=' . $this->config['api_key']));
    }
}
