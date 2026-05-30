<?php

declare(strict_types=1);

namespace app\common\sdk\umfpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 联动优势支付网关客户端。
 */
class UmfpayClient
{
    private const GATEWAY = 'http://pay.soopay.net/spay/pay/payservice.do';

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
     * 发起 POST 接口请求。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function submit(array $payload): array
    {
        $payload = $this->signedPayload($payload);

        try {
            $response = $this->httpClient->post(self::GATEWAY, [
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new UmfpaySdkException('联动优势网关请求失败：' . $e->getMessage(), 0, $e);
        }

        return $this->parseHtml((string) $response->getBody());
    }

    /**
     * 获取跳转支付地址。
     *
     * @param array<string, mixed> $payload 请求参数
     */
    public function payUrl(array $payload): string
    {
        return self::GATEWAY . '?' . http_build_query($this->signedPayload($payload));
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

        return openssl_verify($this->signContent($payload), base64_decode($sign), $this->config['platform_public_key']) === 1;
    }

    /**
     * 生成联动优势通知应答 HTML。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function responseHtml(array $payload, string $retCode = '0000', string $retMsg = 'success'): string
    {
        $response = [
            'sign_type' => 'RSA',
            'version' => '4.0',
            'mer_id' => $this->config['mer_id'],
            'order_id' => (string) ($payload['order_id'] ?? ''),
            'mer_date' => (string) ($payload['mer_date'] ?? ''),
            'ret_code' => $retCode,
            'ret_msg' => $retMsg,
        ];
        $response['sign'] = $this->sign($response);

        $content = http_build_query($response);
        return '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"><html><head><META NAME="MobilePayPlatform" CONTENT="' . htmlspecialchars($content, ENT_QUOTES) . '"></head><body></body></html>';
    }

    /**
     * 公共签名参数。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    private function signedPayload(array $payload): array
    {
        $payload = array_merge([
            'charset' => 'UTF-8',
            'sign_type' => 'RSA',
            'res_format' => 'HTML',
            'version' => '4.0',
            'amt_type' => 'RMB',
            'mer_id' => $this->config['mer_id'],
        ], $payload);
        $payload['sign'] = $this->sign($payload);

        return $payload;
    }

    /**
     * 签名。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function sign(array $payload): string
    {
        if (!openssl_sign($this->signContent($payload), $signature, $this->config['merchant_private_key'])) {
            throw new UmfpaySdkException('联动优势请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 待签名字符串。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function signContent(array $payload): string
    {
        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'sign' && $key !== 'sign_type' && $value !== '') {
                $pieces[] = $key . '=' . (string) $value;
            }
        }

        return implode('&', $pieces);
    }

    /**
     * 解析 HTML META 响应。
     *
     * @return array<string, mixed>
     */
    private function parseHtml(string $html): array
    {
        if (!preg_match('/<META\s+name="MobilePayPlatform"\s+content="([\w\W]*?)"/si', $html, $matches)) {
            throw new UmfpaySdkException('联动优势返回 HTML 解析失败');
        }

        parse_str((string) $matches[1], $data);
        return $data;
    }
}
