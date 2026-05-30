<?php

declare(strict_types=1);

namespace app\common\sdk\swiftpass;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SimpleXMLElement;

/**
 * 威富通 Swiftpass XML 网关轻量客户端。
 */
class SwiftpassClient
{
    private const GATEWAY = 'https://pay.swiftpass.cn/pay/gateway';

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
            'verify' => false,
        ]);
    }

    /**
     * 发起 XML 接口请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        $payload = array_merge([
            'mch_id' => $this->config['mch_id'],
            'version' => '2.0',
            'sign_type' => $this->config['sign_type'] ?: 'RSA_1_256',
            'nonce_str' => bin2hex(random_bytes(16)),
        ], $payload);
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post($this->config['gateway_url'] ?: self::GATEWAY, [
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8'],
                'body' => $this->toXml($payload),
            ]);
        } catch (GuzzleException $e) {
            throw new SwiftpassSdkException('威富通网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = $this->fromXml((string) $response->getBody());
        if (!$this->verify($data)) {
            throw new SwiftpassSdkException('威富通响应验签失败');
        }
        if ((string) ($data['status'] ?? '') !== '0') {
            throw new SwiftpassSdkException((string) ($data['message'] ?? '威富通请求失败'));
        }
        if ((string) ($data['result_code'] ?? '') !== '0') {
            throw new SwiftpassSdkException('[' . (string) ($data['err_code'] ?? '') . ']' . (string) ($data['err_msg'] ?? '威富通业务失败'));
        }

        return $data;
    }

    /**
     * 解析并校验回调 XML。
     *
     * @return array<string, mixed>
     */
    public function notify(string $xml): array
    {
        $data = $this->fromXml($xml);
        if (!$this->verify($data)) {
            throw new SwiftpassSdkException('威富通回调验签失败');
        }

        return $data;
    }

    /**
     * 校验签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        $signContent = $this->signContent($payload);
        if ($this->isRsaSign()) {
            $publicKey = $this->formatPublicKey($this->config['rsa_public_key']);
            $algo = ($this->config['sign_type'] ?: 'RSA_1_256') === 'RSA_1_1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
            return openssl_verify($signContent, base64_decode($sign), $publicKey, $algo) === 1;
        }

        return hash_equals(strtoupper(md5($signContent . '&key=' . $this->config['key'])), strtoupper($sign));
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        $signContent = $this->signContent($payload);
        if ($this->isRsaSign()) {
            $privateKey = $this->formatPrivateKey($this->config['rsa_private_key']);
            $algo = ($this->config['sign_type'] ?: 'RSA_1_256') === 'RSA_1_1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
            if (!openssl_sign($signContent, $signature, $privateKey, $algo)) {
                throw new SwiftpassSdkException('威富通请求签名失败');
            }
            return base64_encode($signature);
        }

        return strtoupper(md5($signContent . '&key=' . $this->config['key']));
    }

    /**
     * 生成待签名字符串。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function signContent(array $payload): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            $pieces[] = $key . '=' . (string) $value;
        }

        return implode('&', $pieces);
    }

    /**
     * 当前签名方式是否为 RSA。
     */
    private function isRsaSign(): bool
    {
        return str_starts_with((string) ($this->config['sign_type'] ?: 'RSA_1_256'), 'RSA');
    }

    /**
     * 数组转 XML。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function toXml(array $payload): string
    {
        $xml = '<xml>';
        foreach ($payload as $key => $value) {
            $value = (string) $value;
            $xml .= is_numeric($value)
                ? "<{$key}>{$value}</{$key}>"
                : "<{$key}><![CDATA[{$value}]]></{$key}>";
        }

        return $xml . '</xml>';
    }

    /**
     * XML 转数组。
     *
     * @return array<string, mixed>
     */
    private function fromXml(string $xml): array
    {
        if ($xml === '') {
            throw new SwiftpassSdkException('威富通响应为空');
        }

        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (!$element instanceof SimpleXMLElement) {
            throw new SwiftpassSdkException('威富通 XML 解析失败');
        }

        return (array) json_decode(json_encode($element, JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * PEM 格式化商户私钥。
     */
    private function formatPrivateKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        return "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * PEM 格式化平台公钥。
     */
    private function formatPublicKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }
}
