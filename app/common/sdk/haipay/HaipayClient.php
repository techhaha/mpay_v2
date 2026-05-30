<?php

declare(strict_types=1);

namespace app\common\sdk\haipay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 海科融通聚合支付轻量客户端。
 */
class HaipayClient
{
    private const PROD_GATEWAY = 'https://saas-front.hkrt.cn';
    private const TEST_GATEWAY = 'http://39.106.187.68:8080';

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
     * 发起支付接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $payload['accessid'] = $this->config['access_id'];
        $payload['req_id'] = date('YmdHis') . random_int(1000, 9999);
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post(($this->config['sandbox'] ? self::TEST_GATEWAY : self::PROD_GATEWAY) . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new HaipaySdkException('海科融通网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new HaipaySdkException('海科融通响应不是合法 JSON');
        }
        if ((string) ($data['result_code'] ?? '') !== '10000') {
            throw new HaipaySdkException((string) ($data['result_msg'] ?? $data['return_msg'] ?? '海科融通请求失败'));
        }

        return $data;
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

        return hash_equals($this->sign($payload), strtoupper($sign));
    }

    /**
     * 生成 MD5 签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        return strtoupper(md5($this->signContent($payload) . $this->config['access_key']));
    }

    /**
     * 构造递归签名字符串。
     *
     * @param array<string, mixed>|mixed $payload 参数
     */
    private function signContent(mixed $payload): string
    {
        if (!is_array($payload)) {
            return (string) $payload;
        }
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            if (is_array($value)) {
                $value = $this->signContent($value);
            }
            $pieces[] = $key . '=' . $value;
        }

        return implode('&', $pieces);
    }
}
