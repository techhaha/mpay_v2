<?php

declare(strict_types=1);

namespace app\common\sdk\ysepay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 银盛支付开放网关轻量客户端。
 */
class YsepayClient
{
    private const QRCODE_GATEWAY = 'https://qrcode.ysepay.com/gateway.do';

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
     * 发起银盛扫码/JSAPI/退款接口。
     *
     * @param string $method 银盛方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, string> $urls 回调地址
     * @return array<string, mixed>
     */
    public function execute(string $method, array $bizContent, array $urls = []): array
    {
        $payload = $this->signedPayload($method, $bizContent, $urls);

        try {
            $response = $this->httpClient->post(self::QRCODE_GATEWAY, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new YsepaySdkException('银盛网关请求失败：' . $e->getMessage(), 0, $e);
        }

        return $this->parseResponse((string) $response->getBody(), $method);
    }

    /**
     * 生成页面跳转接口自动提交表单。
     *
     * @param string $method 银盛方法名
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, string> $urls 回调地址
     */
    public function pageExecute(string $method, array $bizContent, array $urls = []): string
    {
        $payload = $this->signedPayload($method, $bizContent, $urls);
        $html = '<form action="' . self::QRCODE_GATEWAY . '" method="post" id="ysepayForm">';
        foreach ($payload as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</form><script>document.getElementById("ysepayForm").submit();</script>';

        return $html;
    }

    /**
     * 校验回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        return $this->verifyContent($this->signContent($payload), $sign);
    }

    /**
     * 解析银盛响应并验签。
     *
     * @return array<string, mixed>
     */
    private function parseResponse(string $raw, string $method): array
    {
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            throw new YsepaySdkException('银盛响应不是合法 JSON');
        }

        $nodeName = str_replace('.', '_', $method) . '_response';
        $data = (array) ($parsed[$nodeName] ?? []);
        if ($data === []) {
            throw new YsepaySdkException('银盛响应数据节点不存在');
        }
        if ((string) ($data['code'] ?? '') !== '10000') {
            throw new YsepaySdkException('[' . (string) ($data['sub_code'] ?? '') . ']' . (string) ($data['sub_msg'] ?? $data['msg'] ?? '银盛请求失败'));
        }

        $sign = (string) ($parsed['sign'] ?? '');
        if ($sign !== '' && !$this->verifyContent($this->responseSignData($raw, $nodeName), $sign)) {
            throw new YsepaySdkException('银盛响应验签失败');
        }

        return $data;
    }

    /**
     * 构造并签名银盛标准请求参数。
     *
     * @param array<string, mixed> $bizContent 业务参数
     * @param array<string, string> $urls 回调地址
     * @return array<string, string>
     */
    private function signedPayload(string $method, array $bizContent, array $urls): array
    {
        $payload = [
            'method' => $method,
            'partner_id' => $this->config['partner_id'],
            'timestamp' => date('Y-m-d H:i:s'),
            'charset' => 'UTF-8',
            'sign_type' => 'RSA',
            'version' => '3.5',
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        if (($urls['notify_url'] ?? '') !== '') {
            $payload['notify_url'] = $urls['notify_url'];
        }
        if (($urls['return_url'] ?? '') !== '') {
            $payload['return_url'] = $urls['return_url'];
        }
        $payload['sign'] = $this->sign($this->signContent($payload));

        return array_map(static fn (mixed $value): string => (string) $value, $payload);
    }

    /**
     * 截取银盛原始响应中的待验签数据。
     */
    private function responseSignData(string $raw, string $nodeName): string
    {
        $nodeIndex = strpos($raw, $nodeName);
        if ($nodeIndex === false) {
            return '';
        }
        $start = $nodeIndex + strlen($nodeName) + 2;
        $signIndex = strrpos($raw, '"sign"');
        $end = $signIndex === false ? strrpos($raw, '}') : $signIndex - 1;

        return substr($raw, $start, max(0, (int) $end - $start));
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
            if ($value === '' || $value === null || str_starts_with((string) $value, '@')) {
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
        $privateKey = $this->privateKey();
        $signature = '';
        if (!openssl_sign($content, $signature, $privateKey)) {
            throw new YsepaySdkException('银盛请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 平台公钥验签。
     */
    private function verifyContent(string $content, string $sign): bool
    {
        return openssl_verify($content, base64_decode($sign), $this->publicKey()) === 1;
    }

    /**
     * 读取银盛平台证书公钥。
     */
    private function publicKey(): mixed
    {
        $cert = file_get_contents($this->config['platform_cert_path']);
        if ($cert === false) {
            throw new YsepaySdkException('银盛平台证书读取失败');
        }
        $pem = str_contains($cert, 'BEGIN')
            ? $cert
            : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cert), 64, "\n") . "-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new YsepaySdkException('银盛平台证书公钥解析失败');
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
            throw new YsepaySdkException('银盛商户私钥证书解析失败');
        }
        $key = openssl_pkey_get_private($cert['pkey']);
        if ($key === false) {
            throw new YsepaySdkException('银盛商户私钥读取失败');
        }

        return $key;
    }
}
