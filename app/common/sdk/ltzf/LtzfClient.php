<?php

declare(strict_types=1);

namespace app\common\sdk\ltzf;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 蓝兔支付 API 客户端。
 */
class LtzfClient
{
    private const GATEWAY = 'https://api.ltzf.cn';

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
     * 发起表单接口请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @param array<int, string> $signKeys 签名字段顺序
     * @return mixed
     */
    public function post(string $path, array $payload, array $signKeys): mixed
    {
        $payload['mch_id'] = $this->config['mch_id'];
        $payload['timestamp'] = time();
        $payload['sign'] = $this->sign($payload, $signKeys);

        try {
            $response = $this->httpClient->post(self::GATEWAY . $path, [
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new LtzfSdkException('蓝兔支付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new LtzfSdkException('蓝兔支付响应不是合法 JSON');
        }
        if (($data['code'] ?? null) !== 0) {
            throw new LtzfSdkException((string) ($data['msg'] ?? '蓝兔支付请求失败'));
        }

        return $data['data'] ?? [];
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     * @param array<int, string> $signKeys 签名字段顺序
     */
    public function verify(array $payload, array $signKeys): bool
    {
        return hash_equals($this->sign($payload, $signKeys), (string) ($payload['sign'] ?? ''));
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 参数
     * @param array<int, string> $signKeys 签名字段
     */
    private function sign(array $payload, array $signKeys): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $signKeys, true) && $value !== null && $value !== '') {
                $pieces[] = $key . '=' . (string) $value;
            }
        }
        $pieces[] = 'key=' . $this->config['key'];

        return strtoupper(md5(implode('&', $pieces)));
    }
}
