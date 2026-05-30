<?php

declare(strict_types=1);

namespace app\common\sdk\fubei;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 付呗开放接口轻量客户端。
 *
 * 迁移自彩虹 `FubeiClient`：统一公共参数、MD5 签名、JSON 请求和回调验签。
 */
class FubeiClient
{
    private const VERSION = '1.0';
    private const FORMAT = 'json';
    private const SIGN_METHOD = 'md5';

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
     * 发起接口请求。
     *
     * @param string $method 付呗接口方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $method, array $bizContent): array
    {
        $payload = [
            'app_id' => $this->configText('app_id'),
            'method' => $method,
            'format' => self::FORMAT,
            'sign_method' => self::SIGN_METHOD,
            'nonce' => bin2hex(random_bytes(6)),
            'version' => self::VERSION,
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $payload['sign'] = $this->sign($payload);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new FubeiSdkException('付呗请求报文编码失败');
        }

        try {
            $response = $this->httpClient->post($this->gatewayUrl(), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw new FubeiSdkException('付呗网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new FubeiSdkException('付呗响应不是合法 JSON');
        }

        if ((int) ($decoded['result_code'] ?? 0) === 200) {
            $data = $decoded['data'] ?? [];
            return is_array($data) ? $data : [];
        }

        throw new FubeiSdkException((string) ($decoded['result_message'] ?? '付呗请求失败'));
    }

    /**
     * 验证回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');

        return $sign !== '' && hash_equals($this->sign($payload), $sign);
    }

    /**
     * 生成签名。
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
            $pieces[] = $key . '=' . (string) $value;
        }

        return strtoupper(md5(implode('&', $pieces) . $this->configText('app_secret')));
    }

    /**
     * 获取网关地址。
     */
    private function gatewayUrl(): string
    {
        $gateway = $this->configText('api_gateway');

        return $gateway !== '' ? $gateway : 'https://shq-api.51fubei.com/gateway/agent';
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
