<?php

declare(strict_types=1);

namespace app\common\sdk\suixingpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 随行付 OpenAPI 轻量客户端。
 *
 * 按彩虹 `suixingpay` 插件的 RSA 报文规则封装公共参数、签名、
 * 响应验签和 JSON 请求。SDK 独立维护，避免和天阙插件共享实现。
 */
class SuixingpayClient
{
    private const SIGN_TYPE = 'RSA';
    private const VERSION = '1.0';

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
     * 提交 OpenAPI 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $data reqData 业务参数
     * @return array<string, mixed> respData 响应参数
     */
    public function submit(string $path, array $data): array
    {
        $payload = [
            'orgId' => $this->configText('org_id'),
            'reqId' => (string) round(microtime(true) * 1000),
            'reqData' => $data,
            'timestamp' => date('YmdHis'),
            'version' => self::VERSION,
            'signType' => self::SIGN_TYPE,
        ];
        $payload['sign'] = $this->sign($payload);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new SuixingpaySdkException('随行付请求报文编码失败');
        }

        try {
            $response = $this->httpClient->post($this->gatewayUrl($path), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw new SuixingpaySdkException('随行付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new SuixingpaySdkException('随行付响应不是合法 JSON');
        }

        if ((string) ($decoded['code'] ?? '') === '0000') {
            if (!$this->verify($decoded)) {
                throw new SuixingpaySdkException('随行付响应验签失败');
            }

            $data = $decoded['respData'] ?? [];
            return is_array($data) ? $data : [];
        }

        throw new SuixingpaySdkException((string) ($decoded['msg'] ?? '随行付请求失败'));
    }

    /**
     * 校验通知或响应签名。
     *
     * @param array<string, mixed> $payload 待验签报文
     */
    public function verify(array $payload): bool
    {
        $signature = (string) ($payload['sign'] ?? '');
        if ($signature === '') {
            return false;
        }

        return openssl_verify(
            $this->signContent($payload),
            base64_decode($signature, true) ?: '',
            $this->publicKey(),
            OPENSSL_ALGO_SHA1
        ) === 1;
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 待签名报文
     */
    private function sign(array $payload): string
    {
        if (!openssl_sign($this->signContent($payload), $signature, $this->privateKey(), OPENSSL_ALGO_SHA1)) {
            throw new SuixingpaySdkException('随行付请求加签失败');
        }

        return base64_encode($signature);
    }

    /**
     * 构造待签名字符串。
     *
     * @param array<string, mixed> $payload 待签名报文
     */
    private function signContent(array $payload): string
    {
        unset($payload['sign']);
        ksort($payload);

        return implode('&', array_map(
            static fn (string $key, mixed $value): string => $key . '=' . (is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value),
            array_keys($payload),
            $payload
        ));
    }

    /**
     * 商户私钥。
     */
    private function privateKey(): mixed
    {
        $key = $this->pem($this->configText('merchant_private_key'), 'RSA PRIVATE KEY');
        $resource = openssl_pkey_get_private($key);
        if (!$resource) {
            throw new SuixingpaySdkException('随行付商户私钥错误');
        }

        return $resource;
    }

    /**
     * 平台公钥。
     */
    private function publicKey(): mixed
    {
        $key = $this->pem($this->configText('platform_public_key'), 'PUBLIC KEY');
        $resource = openssl_pkey_get_public($key);
        if (!$resource) {
            throw new SuixingpaySdkException('随行付平台公钥错误');
        }

        return $resource;
    }

    /**
     * 补全 PEM 格式。
     */
    private function pem(string $value, string $label): string
    {
        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        return "-----BEGIN {$label}-----\n" . wordwrap(str_replace(["\r", "\n"], '', $value), 64, "\n", true) . "\n-----END {$label}-----";
    }

    /**
     * 拼接网关地址。
     */
    private function gatewayUrl(string $path): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/') . '/' . ltrim($path, '/');
        }

        $base = $this->configBool('sandbox')
            ? 'https://openapi-test.tianquetech.com'
            : 'https://openapi.tianquetech.com';

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }

    /**
     * 获取布尔配置。
     */
    private function configBool(string $key): bool
    {
        return in_array($this->config[$key] ?? false, [true, 1, '1', 'true', 'on'], true);
    }
}
