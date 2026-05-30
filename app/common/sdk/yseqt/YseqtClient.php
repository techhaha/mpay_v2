<?php

declare(strict_types=1);

namespace app\common\sdk\yseqt;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 银盛 e企通轻量客户端。
 */
class YseqtClient
{
    private const GATEWAY = 'https://eqt.ysepay.com/api/trade';

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
            'timeout' => 20,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => false,
        ]);
    }

    /**
     * 发起 e企通交易接口。
     *
     * @param string $serviceNo 服务编号
     * @param array<string, mixed> $bizContent 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $serviceNo, array $bizContent): array
    {
        $payload = [
            'requestId' => date('YmdHis') . random_int(100000, 999999),
            'srcMerchantNo' => $this->config['src_merchant_no'],
            'version' => 'v2.0.0',
            'charset' => 'UTF-8',
            'serviceNo' => $serviceNo,
            'signType' => 'RSA',
            'bizReqJson' => json_encode($bizContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        $payload['sign'] = $this->sign($this->signContent($payload));

        try {
            $response = $this->httpClient->post($this->config['gateway_url'] ?: self::GATEWAY, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new YseqtSdkException('银盛e企通网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new YseqtSdkException('银盛e企通响应不是合法 JSON');
        }
        if (($data['sign'] ?? '') !== '' && !$this->verify($data)) {
            throw new YseqtSdkException('银盛e企通响应验签失败');
        }
        if ((string) ($data['code'] ?? '') !== 'SYS000') {
            throw new YseqtSdkException((string) ($data['msg'] ?? '银盛e企通请求失败'));
        }

        $biz = $data['bizResponseJson'] ?? [];
        if (is_string($biz)) {
            $biz = json_decode($biz, true);
        }

        return is_array($biz) ? $biz : [];
    }

    /**
     * 校验回调或响应签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        if (isset($payload['bizResponseJson']) && is_array($payload['bizResponseJson'])) {
            $payload['bizResponseJson'] = json_encode($payload['bizResponseJson'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return openssl_verify($this->signContent($payload), base64_decode($sign), $this->publicKey()) === 1;
    }

    /**
     * 构造待签名字符串。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function signContent(array $payload): string
    {
        ksort($payload);
        unset($payload['sign']);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($value === null || str_starts_with((string) $value, '@')) {
                continue;
            }
            $pieces[] = $key . '=' . (string) $value;
        }

        return implode('&', $pieces);
    }

    /**
     * 商户私钥签名。
     */
    private function sign(string $content): string
    {
        $signature = '';
        if (!openssl_sign($content, $signature, $this->privateKey())) {
            throw new YseqtSdkException('银盛e企通请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 读取平台证书公钥。
     */
    private function publicKey(): mixed
    {
        $cert = file_get_contents($this->config['platform_cert_path']);
        if ($cert === false) {
            throw new YseqtSdkException('银盛e企通平台证书读取失败');
        }
        $pem = str_contains($cert, 'BEGIN')
            ? $cert
            : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cert), 64, "\n") . "-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new YseqtSdkException('银盛e企通平台证书公钥解析失败');
        }

        return $key;
    }

    /**
     * 读取商户 PFX 私钥。
     */
    private function privateKey(): mixed
    {
        $pfx = file_get_contents($this->config['private_cert_path']);
        if ($pfx === false || !openssl_pkcs12_read($pfx, $cert, $this->config['private_cert_password'])) {
            throw new YseqtSdkException('银盛e企通商户私钥证书解析失败');
        }
        $key = openssl_pkey_get_private($cert['pkey']);
        if ($key === false) {
            throw new YseqtSdkException('银盛e企通商户私钥读取失败');
        }

        return $key;
    }
}
