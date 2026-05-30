<?php

declare(strict_types=1);

namespace app\common\sdk\xunhupay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 虎皮椒支付轻量客户端。
 */
class XunhupayClient
{
    private const DEFAULT_PAY_URL = 'https://api.xunhupay.com/payment/do.html';

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
     * 发起支付下单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function pay(array $params): array
    {
        return $this->submit($this->payUrl(), $params);
    }

    /**
     * 查询订单。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function query(array $params): array
    {
        return $this->submit(str_replace('/payment/do.html', '/payment/query.html', $this->payUrl()), $params);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function refund(array $params): array
    {
        return $this->submit(str_replace('/payment/do.html', '/payment/refund.html', $this->payUrl()), $params);
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        return isset($payload['hash']) && hash_equals((string) $payload['hash'], $this->sign($payload));
    }

    /**
     * 解析虎皮椒二维码图片地址中的真实二维码内容。
     */
    public function parseQrcode(string $qrcodeUrl): string
    {
        $target = $this->redirectUrl($qrcodeUrl) ?: $qrcodeUrl;
        $query = parse_url($target, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);
        $data = (string) ($params['data'] ?? '');
        $decoded = $data !== '' ? base64_decode($data, true) : false;
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        throw new XunhupaySdkException('虎皮椒未返回有效二维码内容');
    }

    /**
     * 提交 JSON 请求并验签响应。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    private function submit(string $url, array $params): array
    {
        $payload = array_merge([
            'appid' => $this->configText('appid'),
            'time' => time(),
            'nonce_str' => bin2hex(random_bytes(8)),
        ], $params);
        $payload['hash'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (GuzzleException $e) {
            throw new XunhupaySdkException('虎皮椒请求失败：' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new XunhupaySdkException('虎皮椒响应不是合法 JSON');
        }
        if ((int) ($decoded['errcode'] ?? -1) !== 0) {
            throw new XunhupaySdkException((string) ($decoded['errmsg'] ?? '虎皮椒请求失败'));
        }
        if (!$this->verify($decoded)) {
            throw new XunhupaySdkException('虎皮椒响应验签失败');
        }

        return $decoded;
    }

    /**
     * 生成 MD5 签名。
     *
     * @param array<string, mixed> $params 待签名参数
     */
    private function sign(array $params): string
    {
        unset($params['hash']);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $pairs[] = $key . '=' . $value;
            }
        }

        return md5(implode('&', $pairs) . $this->configText('api_key'));
    }

    /**
     * 获取跳转后的地址。
     */
    private function redirectUrl(string $url): string
    {
        try {
            $response = $this->httpClient->get($url, [
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return '';
        }

        return (string) ($response->getHeaderLine('Location') ?: '');
    }

    /**
     * 支付网关。
     */
    private function payUrl(): string
    {
        $url = $this->configText('api_url');
        return $url !== '' ? $url : self::DEFAULT_PAY_URL;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
