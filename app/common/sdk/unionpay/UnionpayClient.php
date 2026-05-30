<?php

declare(strict_types=1);

namespace app\common\sdk\unionpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SimpleXMLElement;

/**
 * 银联前置 Swiftpass 协议轻量客户端。
 */
class UnionpayClient
{
    private const GATEWAY = 'https://qra.95516.com/pay/gateway';

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
            'sign_type' => 'MD5',
            'nonce_str' => bin2hex(random_bytes(16)),
        ], $payload);
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post($this->config['gateway_url'] ?: self::GATEWAY, [
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8'],
                'body' => $this->toXml($payload),
            ]);
        } catch (GuzzleException $e) {
            throw new UnionpaySdkException('银联前置网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = $this->fromXml((string) $response->getBody());
        if (!$this->verify($data)) {
            throw new UnionpaySdkException('银联前置响应验签失败');
        }
        if ((string) ($data['status'] ?? '') !== '0') {
            throw new UnionpaySdkException((string) ($data['message'] ?? '银联前置请求失败'));
        }
        if ((string) ($data['result_code'] ?? '') !== '0') {
            throw new UnionpaySdkException('[' . (string) ($data['err_code'] ?? '') . ']' . (string) ($data['err_msg'] ?? '银联前置业务失败'));
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
            throw new UnionpaySdkException('银联前置回调验签失败');
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
            $pieces[] = $key . '=' . (string) $value;
        }

        return strtoupper(md5(implode('&', $pieces) . '&key=' . $this->config['key']));
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
            throw new UnionpaySdkException('银联前置响应为空');
        }

        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (!$element instanceof SimpleXMLElement) {
            throw new UnionpaySdkException('银联前置 XML 解析失败');
        }

        return (array) json_decode(json_encode($element, JSON_UNESCAPED_UNICODE), true);
    }
}
