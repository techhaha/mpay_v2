<?php

declare(strict_types=1);

namespace app\common\sdk\allinpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 通联支付轻量客户端。
 *
 * 迁移自彩虹 `PayService`：公共参数、RSA 签名、表单请求和回调验签。
 */
class AllinpayClient
{
    private const SIGN_TYPE = 'RSA';
    private const VERSION = '11';

    /**
     * SDK 配置。
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * HTTP 客户端。
     */
    private Client $httpClient;

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $config SDK 配置
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
     * 发起表单接口请求。
     *
     * @param string $url 接口地址
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function submit(string $url, array $params): array
    {
        $payload = $this->signedPayload($params, self::VERSION);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ],
                'form_params' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new AllinpaySdkException('通联网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new AllinpaySdkException('通联响应不是合法 JSON');
        }
        if ((string) ($data['retcode'] ?? '') !== 'SUCCESS') {
            throw new AllinpaySdkException((string) ($data['retmsg'] ?? '通联请求失败'));
        }

        return $data;
    }

    /**
     * 构造通联收银台签名参数。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function cashierPayload(array $params): array
    {
        return $this->signedPayload($params, '12');
    }

    /**
     * 验证通联回调签名。
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
     * 构造带签名的请求参数。
     *
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    private function signedPayload(array $params, string $version): array
    {
        $payload = array_merge([
            'appid' => $this->configText('app_id'),
            'cusid' => $this->configText('merchant_no'),
            'version' => $version,
            'randomstr' => bin2hex(random_bytes(8)),
            'signtype' => self::SIGN_TYPE,
        ], $params);
        $payload['sign'] = $this->sign($this->signContent($payload));

        return $payload;
    }

    /**
     * 构造待签名字符串。
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
     * 商户私钥签名。
     */
    private function sign(string $content): string
    {
        $privateKey = openssl_pkey_get_private($this->pemKey($this->configText('merchant_private_key'), 'private'));
        if ($privateKey === false) {
            throw new AllinpaySdkException('通联商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new AllinpaySdkException('通联请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 平台公钥验签。
     */
    private function verifyContent(string $content, string $sign): bool
    {
        $publicKey = openssl_pkey_get_public($this->pemKey($this->configText('platform_public_key'), 'public'));
        if ($publicKey === false) {
            throw new AllinpaySdkException('通联平台公钥不正确');
        }

        return openssl_verify($content, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * 规范化 PEM 密钥。
     */
    private function pemKey(string $key, string $type): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $header = $type === 'private' ? 'RSA PRIVATE KEY' : 'PUBLIC KEY';

        return "-----BEGIN {$header}-----\n"
            . wordwrap(str_replace(["\r", "\n"], '', $key), 64, "\n", true)
            . "\n-----END {$header}-----";
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
