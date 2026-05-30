<?php

declare(strict_types=1);

namespace app\common\sdk\fuiou;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 富友合作方聚合支付轻量客户端。
 *
 * 迁移自彩虹 `PayService`：统一公共参数、GBK XML 报文、RSA-MD5 签名、
 * 富友响应验签和回调验签。业务产品字段由支付插件层传入。
 */
class FuiouPayClient
{
    private const VERSION = '1.0';
    private const TERM_ID = '88888888';
    private const PROD_GATEWAY = 'https://spay-mc.fuioupay.com';
    private const TEST_GATEWAY = 'https://fundwx.fuiou.com';

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
     * 发起富友接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function submit(string $path, array $params): array
    {
        $payload = array_merge([
            'version' => self::VERSION,
            'ins_cd' => $this->configText('institution_code'),
            'mchnt_cd' => $this->configText('merchant_no'),
            'term_id' => self::TERM_ID,
            'random_str' => bin2hex(random_bytes(8)),
        ], $this->toGbkPayload($params));
        $payload['sign'] = $this->sign($payload);

        try {
            $response = $this->httpClient->post($this->gatewayUrl() . $path, [
                'headers' => [
                    'Accept' => '*/*',
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=GBK',
                ],
                'body' => 'req=' . urlencode(urlencode($this->toXml($payload))),
            ]);
        } catch (GuzzleException $e) {
            throw new FuiouSdkException('富友网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $result = $this->parseXml(urldecode((string) $response->getBody()));
        $resultCode = (string) ($result['result_code'] ?? '');
        if (in_array($resultCode, ['000000', '030010'], true)) {
            if (!$this->verifyResponse($result)) {
                throw new FuiouSdkException('富友响应验签失败');
            }

            return $result;
        }

        throw new FuiouSdkException((string) ($result['result_msg'] ?? '富友返回失败'));
    }

    /**
     * 兼容彩虹旧插件里的 request 命名，语义与 submit 一致。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function request(string $path, array $params): array
    {
        return $this->submit($path, $params);
    }

    /**
     * 解析富友回调中的 XML 报文。
     *
     * @param string $xml XML 文本
     * @return array<string, mixed>
     */
    public function parseXml(string $xml): array
    {
        if ($xml === '') {
            throw new FuiouSdkException('富友响应为空');
        }

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($element === false) {
            throw new FuiouSdkException('富友 XML 解析失败');
        }

        $json = json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            throw new FuiouSdkException('富友 XML 转换失败');
        }

        return $data;
    }

    /**
     * 验证富友回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verifyNotify(array $payload): bool
    {
        return $this->verify($payload, false);
    }

    /**
     * 验证富友响应签名。
     *
     * 富友响应字段参与签名前需要按旧 SDK 逻辑转为 GBK。
     *
     * @param array<string, mixed> $payload 响应参数
     */
    private function verifyResponse(array $payload): bool
    {
        return $this->verify($this->toGbkPayload($payload), true);
    }

    /**
     * 生成 RSA-MD5 签名。
     *
     * @param array<string, mixed> $payload 待签名参数
     */
    private function sign(array $payload): string
    {
        $privateKey = openssl_pkey_get_private($this->pemKey($this->configText('merchant_private_key'), 'private'));
        if ($privateKey === false) {
            throw new FuiouSdkException('富友商户私钥不正确');
        }

        $signature = '';
        if (!openssl_sign($this->signContent($payload), $signature, $privateKey, OPENSSL_ALGO_MD5)) {
            throw new FuiouSdkException('富友请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 验证 RSA-MD5 签名。
     *
     * @param array<string, mixed> $payload 待验签参数
     * @param bool $strict 是否严格要求 sign 字段存在
     */
    private function verify(array $payload, bool $strict): bool
    {
        $signature = (string) ($payload['sign'] ?? '');
        if ($signature === '') {
            return !$strict;
        }

        $publicKey = openssl_pkey_get_public($this->pemKey($this->configText('platform_public_key'), 'public'));
        if ($publicKey === false) {
            throw new FuiouSdkException('富友平台公钥不正确');
        }

        return openssl_verify($this->signContent($payload), base64_decode($signature), $publicKey, OPENSSL_ALGO_MD5) === 1;
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
            if ($key === 'sign' || str_starts_with((string) $key, 'reserved')) {
                continue;
            }
            $pieces[] = $key . '=' . (is_array($value) ? '' : (string) $value);
        }

        return implode('&', $pieces);
    }

    /**
     * 构造富友 XML 报文。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function toXml(array $payload): string
    {
        return '<?xml version="1.0" encoding="GBK" standalone="yes"?><xml>'
            . $this->xmlNodes($payload)
            . '</xml>';
    }

    /**
     * 递归构造 XML 节点。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function xmlNodes(array $payload): string
    {
        $xml = '';
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $xml .= '<' . $key . '>' . $this->xmlNodes($value) . '</' . $key . '>';
                continue;
            }

            $xml .= '<' . $key . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'GBK') . '</' . $key . '>';
        }

        return $xml;
    }

    /**
     * 将请求字段转换为富友要求的 GBK 编码。
     *
     * @param array<string, mixed> $payload 参数
     * @return array<string, mixed>
     */
    private function toGbkPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value) && $value !== '') {
                $payload[$key] = mb_convert_encoding($value, 'GBK', 'UTF-8');
            }
        }

        return $payload;
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
     * 获取网关地址。
     */
    private function gatewayUrl(): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return (bool) ($this->config['sandbox'] ?? false) ? self::TEST_GATEWAY : self::PROD_GATEWAY;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }
}
