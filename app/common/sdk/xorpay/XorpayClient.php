<?php

declare(strict_types=1);

namespace app\common\sdk\xorpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * XorPay 轻量客户端。
 */
class XorpayClient
{
    private const BASE_URL = 'https://xorpay.com';

    private Client $httpClient;

    /**
     * @param array<string, mixed> $config SDK 配置
     */
    public function __construct(private array $config)
    {
        $this->httpClient = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'verify' => true,
        ]);
    }

    /**
     * 扫码下单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function pay(array $params): array
    {
        $params['sign'] = md5(
            (string) $params['name']
            . (string) $params['pay_type']
            . (string) $params['price']
            . (string) $params['order_id']
            . (string) $params['notify_url']
            . $this->configText('app_secret')
        );

        return $this->post(self::BASE_URL . '/api/pay/' . rawurlencode($this->configText('app_id')), $params);
    }

    /**
     * 微信收银台 HTML 表单参数。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function cashierPayload(array $params): array
    {
        $params['sign'] = md5(
            (string) $params['name']
            . (string) $params['pay_type']
            . (string) $params['price']
            . (string) $params['order_id']
            . (string) $params['notify_url']
            . $this->configText('app_secret')
        );

        return $params;
    }

    /**
     * 申请退款。
     *
     * @param string $channelTradeNo XorPay 订单号
     * @param string $amount 退款金额，单位元
     * @return array<string, mixed>
     */
    public function refund(string $channelTradeNo, string $amount): array
    {
        return $this->post(self::BASE_URL . '/api/refund/' . rawurlencode($channelTradeNo), [
            'price' => $amount,
            'sign' => md5($amount . $this->configText('app_secret')),
        ]);
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = md5(
            (string) ($payload['aoid'] ?? '')
            . (string) ($payload['order_id'] ?? '')
            . (string) ($payload['pay_price'] ?? '')
            . (string) ($payload['pay_time'] ?? '')
            . $this->configText('app_secret')
        );

        return isset($payload['sign']) && hash_equals((string) $payload['sign'], $sign);
    }

    /**
     * 提交表单请求。
     *
     * @param array<string, mixed> $params 表单参数
     * @return array<string, mixed>
     */
    private function post(string $url, array $params): array
    {
        try {
            $response = $this->httpClient->post($url, [
                'form_params' => $params,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw new XorpaySdkException('XorPay 请求失败：' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new XorpaySdkException('XorPay 响应不是合法 JSON');
        }
        if ((string) ($decoded['status'] ?? '') !== 'ok') {
            throw new XorpaySdkException((string) ($decoded['info'] ?? $decoded['status'] ?? 'XorPay 请求失败'));
        }

        return $decoded;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
