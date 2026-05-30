<?php

declare(strict_types=1);

namespace app\common\sdk\leshua;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SimpleXMLElement;

/**
 * 乐刷聚合支付 XML 网关客户端。
 */
class LeshuaClient
{
    private const GATEWAY = 'https://paygate.leshuazf.com/cgi-bin/lepos_pay_gateway.cgi';

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
     * 发起网关请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function request(array $payload): array
    {
        $payload['merchant_id'] = $this->config['merchant_id'];
        $payload['nonce_str'] = bin2hex(random_bytes(16));
        $payload['sign'] = $this->sign($payload, $this->config['trade_key']);

        try {
            $response = $this->httpClient->post(self::GATEWAY, [
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new LeshuaSdkException('乐刷网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = $this->fromXml((string) $response->getBody());
        if ((string) ($data['resp_code'] ?? '') !== '0') {
            throw new LeshuaSdkException((string) ($data['resp_msg'] ?? '乐刷请求失败'));
        }
        if ((string) ($data['result_code'] ?? '') !== '0') {
            throw new LeshuaSdkException((string) ($data['error_msg'] ?? '乐刷业务失败'));
        }

        return $data;
    }

    /**
     * 校验异步通知签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        return strtolower($this->sign($payload, $this->config['notify_key'])) === strtolower($sign);
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function sign(array $payload, string $key): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $name => $value) {
            if ($name === 'sign' || $name === 'error_code') {
                continue;
            }
            if (is_array($value)) {
                $value = '';
            }
            $pieces[] = $name . '=' . (string) $value;
        }
        $pieces[] = 'key=' . $key;

        return strtoupper(md5(implode('&', $pieces)));
    }

    /**
     * XML 转数组。
     *
     * @return array<string, mixed>
     */
    public function fromXml(string $xml): array
    {
        if ($xml === '') {
            throw new LeshuaSdkException('乐刷响应为空');
        }

        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (!$element instanceof SimpleXMLElement) {
            throw new LeshuaSdkException('乐刷 XML 解析失败');
        }

        return (array) json_decode(json_encode($element, JSON_UNESCAPED_UNICODE), true);
    }
}
