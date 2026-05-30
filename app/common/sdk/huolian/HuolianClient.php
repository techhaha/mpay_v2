<?php

declare(strict_types=1);

namespace app\common\sdk\huolian;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 火脸支付开放 API 客户端。
 */
class HuolianClient
{
    private const GATEWAY = 'https://open.lianok.com/open/v1/api/biz/do';

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
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $resource, array $params): array
    {
        $params = array_filter($params, static fn (mixed $value): bool => $value !== null);
        $payload = [
            'authCode' => $this->config['auth_code'],
            'requestTime' => date('YmdHis'),
            'resource' => $resource,
            'versionNo' => '1',
        ];
        $payload['sign'] = $this->sign(array_merge($payload, $params));
        $payload['params'] = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->httpClient->post(self::GATEWAY, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new HuolianSdkException('火脸支付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new HuolianSdkException('火脸支付响应不是合法 JSON');
        }
        if ((int) ($data['code'] ?? -1) !== 0 || (int) ($data['status'] ?? 0) !== 200) {
            throw new HuolianSdkException((string) ($data['message'] ?? '火脸支付请求失败'));
        }

        return (array) ($data['data'] ?? []);
    }

    /**
     * 校验通知签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        unset($payload['code'], $payload['message']);

        return hash_equals($this->sign($payload), $sign);
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
            if ($key !== 'sign' && $value !== null) {
                $pieces[] = $key . '=' . (string) $value;
            }
        }

        return md5(strtolower(implode('&', $pieces) . '&') . $this->config['salt']);
    }
}
