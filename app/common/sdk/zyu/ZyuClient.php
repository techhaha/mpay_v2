<?php

declare(strict_types=1);

namespace app\common\sdk\zyu;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 知宇支付轻量客户端。
 */
class ZyuClient
{
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
     * 构造下单参数。
     *
     * @param array<string, mixed> $params 下单参数
     * @return array<string, mixed>
     */
    public function payPayload(array $params): array
    {
        $params['pay_md5sign'] = $this->sign($params);
        $params['pay_productname'] = (string) ($params['pay_productname'] ?? '');

        return $params;
    }

    /**
     * 请求下单接口。
     *
     * @param array<string, mixed> $params 下单参数
     * @return array<string, mixed>
     */
    public function pay(array $params): array
    {
        try {
            $response = $this->httpClient->post($this->configText('api_url'), [
                'form_params' => $this->payPayload($params),
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw new ZyuSdkException('知宇支付请求失败：' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new ZyuSdkException('知宇支付响应不是合法 JSON');
        }
        if (!$this->isSuccess($decoded)) {
            throw new ZyuSdkException((string) ($decoded['msg'] ?? '知宇支付创建订单失败'));
        }

        return $decoded;
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $data = [
            'memberid' => (string) ($payload['memberid'] ?? ''),
            'orderid' => (string) ($payload['orderid'] ?? ''),
            'amount' => (string) ($payload['amount'] ?? ''),
            'datetime' => (string) ($payload['datetime'] ?? ''),
            'transaction_id' => (string) ($payload['transaction_id'] ?? ''),
            'returncode' => (string) ($payload['returncode'] ?? ''),
        ];

        return isset($payload['sign']) && hash_equals((string) $payload['sign'], $this->sign($data));
    }

    /**
     * 响应是否成功。
     *
     * @param array<string, mixed> $data 响应数据
     */
    private function isSuccess(array $data): bool
    {
        return in_array((string) ($data['status'] ?? ''), ['200', 'success', '1'], true)
            || (string) ($data['code'] ?? '') === '200';
    }

    /**
     * 生成大写 MD5 签名。
     *
     * @param array<string, mixed> $params 待签名参数
     */
    private function sign(array $params): string
    {
        unset($params['sign'], $params['pay_md5sign'], $params['pay_productname']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }

        return strtoupper(md5(implode('&', $pairs) . '&key=' . $this->configText('api_key')));
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
