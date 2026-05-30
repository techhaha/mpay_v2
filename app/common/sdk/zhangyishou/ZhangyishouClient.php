<?php

declare(strict_types=1);

namespace app\common\sdk\zhangyishou;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 掌易收支付轻量客户端。
 */
class ZhangyishouClient
{
    private const ADD_ORDER_URL = 'https://apipay.zhangyishou.com/api/Order/AddOrder';
    private const REFUND_URL = 'https://apipay.zhangyishou.com/api/OrderRefund/Refund';

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
     * 创建支付订单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function addOrder(array $params): array
    {
        $params['MD5Sign'] = $this->sign($params);
        $params['MerchantNo'] = $this->configText('merchant_no');
        $params['Mproductdesc'] = (string) ($params['Mproductdesc'] ?? '');

        return $this->post(self::ADD_ORDER_URL, $params);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function refund(array $params): array
    {
        $params['MD5Sign'] = $this->sign($params);

        return $this->post(self::REFUND_URL, $params);
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = md5((string) ($payload['MerchantId'] ?? '') . (string) ($payload['DownstreamOrderNo'] ?? '') . $this->configText('api_key'));

        return isset($payload['Signature']) && hash_equals((string) $payload['Signature'], $sign);
    }

    /**
     * 提交 JSON 请求。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    private function post(string $url, array $params): array
    {
        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (GuzzleException $e) {
            throw new ZhangyishouSdkException('掌易收请求失败：' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new ZhangyishouSdkException('掌易收响应不是合法 JSON');
        }
        if ((string) ($decoded['Code'] ?? '') !== '1009') {
            throw new ZhangyishouSdkException((string) ($decoded['Message'] ?? $decoded['Info'] ?? '掌易收请求失败'));
        }

        return $decoded;
    }

    /**
     * 按掌易收规则拼接字段值后 MD5。
     *
     * @param array<string, mixed> $params 待签名参数
     */
    private function sign(array $params): string
    {
        unset($params['MD5Sign'], $params['MerchantNo'], $params['Mproductdesc']);

        return md5(implode('', array_values($params)) . $this->configText('api_key'));
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
