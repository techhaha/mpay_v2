<?php

declare(strict_types=1);

namespace app\common\sdk\easypay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 易生易企通轻量客户端。
 */
class EasypayClient
{
    private const PROD_GATEWAY = 'https://phoenix.eycard.cn/yqt';
    private const TEST_GATEWAY = 'https://d-phoenix-gap.easypay.com.cn:24443/yqt';

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
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 发起易企通接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $body 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $body): array
    {
        $header = [
            'transTime' => date('YmdHis'),
            'reqId' => $this->config['req_id'],
            'reqType' => $this->config['req_type'],
        ];
        $payload = [
            'reqBody' => $body,
            'reqHeader' => $header,
            'reqSign' => $this->sign($header, $body),
        ];

        try {
            $response = $this->httpClient->post(($this->config['sandbox'] ? self::TEST_GATEWAY : self::PROD_GATEWAY) . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new EasypaySdkException('易生网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new EasypaySdkException('易生响应不是合法 JSON');
        }
        if ((string) ($data['rspHeader']['rspCode'] ?? '') !== '000000') {
            throw new EasypaySdkException('[' . (string) ($data['rspHeader']['rspCode'] ?? '') . ']' . (string) ($data['rspHeader']['rspInfo'] ?? '易生请求失败'));
        }
        if (!$this->verify((array) $data['rspHeader'], (array) $data['rspBody'], (string) ($data['rspSign'] ?? ''))) {
            throw new EasypaySdkException('易生响应验签失败');
        }

        return (array) $data['rspBody'];
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $header 响应头
     * @param array<string, mixed> $body 响应体
     */
    public function verify(array $header, array $body, string $sign): bool
    {
        if ($sign === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->pemKey((string) $this->config['platform_public_key'], 'public'));
        if ($publicKey === false) {
            throw new EasypaySdkException('易生公钥不正确');
        }

        return openssl_verify($this->signContent($header, $body), base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 生成请求签名。
     *
     * @param array<string, mixed> $header 请求头
     * @param array<string, mixed> $body 请求体
     */
    private function sign(array $header, array $body): string
    {
        $privateKey = openssl_pkey_get_private($this->pemKey((string) $this->config['merchant_private_key'], 'private'));
        if ($privateKey === false) {
            throw new EasypaySdkException('易生商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($this->signContent($header, $body), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new EasypaySdkException('易生请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 构造待签名字符串。
     *
     * @param array<string, mixed> $header 请求头
     * @param array<string, mixed> $body 请求体
     */
    private function signContent(array $header, array $body): string
    {
        $sortedHeader = $this->sortRecursive($header);
        $sortedBody = $this->sortRecursive($body);

        return json_encode($sortedHeader, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)
            . strtoupper(md5((string) json_encode($sortedBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)));
    }

    /**
     * 递归按键排序。
     *
     * @param array<string, mixed> $payload 参数
     * @return array<string, mixed>
     */
    private function sortRecursive(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursive($value);
            }
        }

        return $payload;
    }

    /**
     * 规范化 PEM 密钥。
     */
    private function pemKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $label = $type === 'private' ? 'RSA PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$label}-----\n" . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true) . "\n-----END {$label}-----";
    }
}
