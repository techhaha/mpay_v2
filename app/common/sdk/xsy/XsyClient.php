<?php

declare(strict_types=1);

namespace app\common\sdk\xsy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 新生易订单网关客户端。
 */
class XsyClient
{
    private const GATEWAY = 'https://gateway-hpx.hnapay.com/order';
    private const TEST_GATEWAY = 'https://gateway-hpxtest1.hnapay.com/order';

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
     * 最近一次响应码。
     */
    private string $responseCode = '';

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
     * 发起订单网关请求。
     *
     * @param array<string, mixed> $data 业务参数
     * @return array<string, mixed>
     */
    public function request(string $path, array $data): array
    {
        $data = array_filter($data, static fn (mixed $value): bool => $value !== null);
        $payload = [
            'reqId' => bin2hex(random_bytes(30)),
            'orgNo' => $this->config['org_no'],
            'reqData' => $data,
            'signType' => 'RSA',
            'timestamp' => (string) (int) (microtime(true) * 1000),
            'version' => '1.0',
        ];
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post($this->gateway() . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new XsySdkException('新生易网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new XsySdkException('新生易响应不是合法 JSON');
        }
        $this->responseCode = (string) ($result['code'] ?? '');
        if (!in_array($this->responseCode, ['0000', '0001'], true)) {
            throw new XsySdkException((string) ($result['msg'] ?? '新生易请求失败'));
        }

        return (array) ($result['respData'] ?? []);
    }

    /**
     * 获取最近响应码。
     */
    public function responseCode(): string
    {
        return $this->responseCode;
    }

    /**
     * 校验回调签名。
     */
    public function verify(string $rawBody): bool
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['sign'])) {
            return false;
        }

        return openssl_verify($this->signContent($payload, $rawBody), base64_decode((string) $payload['sign']), $this->formatPublicKey($this->config['platform_public_key'])) === 1;
    }

    /**
     * 请求参数签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        if (!openssl_sign($this->signContent($payload), $signature, $this->formatPrivateKey($this->config['merchant_private_key']))) {
            throw new XsySdkException('新生易请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 待签名字符串。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function signContent(array $payload, ?string $rawBody = null): string
    {
        if (isset($payload['respData']) && is_array($payload['respData'])) {
            $payload['respData'] = $rawBody
                ? $this->extractRawRespData($rawBody)
                : json_encode(array_filter($payload['respData'], static fn (mixed $value): bool => $value !== '' && $value !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (isset($payload['reqData']) && is_array($payload['reqData'])) {
            $payload['reqData'] = json_encode($payload['reqData'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

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
     * 从原始 JSON 中取出未重排的 respData。
     */
    private function extractRawRespData(string $rawBody): string
    {
        $start = strpos($rawBody, '"respData":');
        $end = strrpos($rawBody, ',"sign"');
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        return substr($rawBody, $start + 11, $end - $start - 11);
    }

    /**
     * 当前网关地址。
     */
    private function gateway(): string
    {
        return $this->config['is_test'] === '1' ? self::TEST_GATEWAY : self::GATEWAY;
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
