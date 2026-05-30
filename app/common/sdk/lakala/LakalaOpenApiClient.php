<?php

declare(strict_types=1);

namespace app\common\sdk\lakala;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 拉卡拉 OpenAPI 轻量客户端。
 *
 * 依据拉卡拉开放平台 LKLAPI-SHA256withRSA 规则封装公共报文、请求头签名、
 * 回调验签和响应解析。该类不关心 MPAY 订单生命周期，只返回渠道响应数据。
 */
class LakalaOpenApiClient
{
    private const SCHEMA = 'LKLAPI-SHA256withRSA';

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
     * 最近一次请求报文。
     */
    private string $lastRequestBody = '';

    /**
     * 最近一次响应报文。
     */
    private string $lastResponseBody = '';

    /**
     * 最近一次渠道返回码。
     */
    private string $lastCode = '';

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
     * 聚合支付接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params req_data 内容
     * @return array<string, mixed> resp_data 内容
     */
    public function execute(string $path, array $params): array
    {
        return $this->postJson($path, [
            'req_time' => date('YmdHis'),
            'version' => '3.0',
            'req_data' => $params,
        ], ['BBS00000', 'BBS10000']);
    }

    /**
     * 拉卡拉收银台接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params req_data 内容
     * @return array<string, mixed> resp_data 内容
     */
    public function cashier(string $path, array $params): array
    {
        return $this->postJson($path, [
            'req_time' => date('YmdHis'),
            'version' => '1.0',
            'req_data' => $params,
        ], ['000000']);
    }

    /**
     * 校验拉卡拉异步通知签名。
     *
     * @param string $authorization Authorization 请求头
     * @param string $body 原始请求体
     * @return bool 是否验签通过
     */
    public function verifyNotify(string $authorization, string $body): bool
    {
        $payload = $this->parseAuthorization($authorization);
        $signature = (string) ($payload['signature'] ?? '');
        $timestamp = (string) ($payload['timestamp'] ?? '');
        $nonce = (string) ($payload['nonce_str'] ?? '');

        if ($signature === '' || $timestamp === '' || $nonce === '') {
            return false;
        }

        return $this->rsaPublicVerify($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature);
    }

    /**
     * 获取最近一次请求报文。
     */
    public function lastRequestBody(): string
    {
        return $this->lastRequestBody;
    }

    /**
     * 获取最近一次响应报文。
     */
    public function lastResponseBody(): string
    {
        return $this->lastResponseBody;
    }

    /**
     * 获取最近一次渠道返回码。
     */
    public function lastCode(): string
    {
        return $this->lastCode;
    }

    /**
     * 发送签名 JSON 请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $body 公共报文
     * @param array<int, string> $successCodes 成功返回码
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body, array $successCodes): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new LakalaSdkException('拉卡拉请求报文编码失败');
        }

        $this->lastRequestBody = $json;
        $url = $this->gatewayUrl($path);

        try {
            $response = $this->httpClient->post($url, [
                'headers' => [
                    'Authorization' => $this->authorization($json),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw new LakalaSdkException('拉卡拉网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $this->lastResponseBody = (string) $response->getBody();
        $decoded = json_decode($this->lastResponseBody, true);
        if (!is_array($decoded)) {
            throw new LakalaSdkException('拉卡拉响应不是合法 JSON');
        }

        $code = (string) ($decoded['code'] ?? $decoded['retCode'] ?? '');
        $this->lastCode = $code;
        if (in_array($code, $successCodes, true)) {
            $data = $decoded['resp_data'] ?? $decoded['respData'] ?? [];
            return is_array($data) ? $data : [];
        }

        $message = (string) ($decoded['msg'] ?? $decoded['retMsg'] ?? $decoded['resMsg'] ?? '拉卡拉请求失败');
        throw new LakalaSdkException($code !== '' ? '[' . $code . ']' . $message : $message);
    }

    /**
     * 构造 Authorization 请求头。
     */
    private function authorization(string $body): string
    {
        $appId = $this->configText('app_id');
        $serialNo = $this->merchantSerialNo();
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $message = $appId . "\n" . $serialNo . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";

        return self::SCHEMA
            . ' appid="' . $appId . '",'
            . 'serial_no="' . $serialNo . '",'
            . 'timestamp="' . $timestamp . '",'
            . 'nonce_str="' . $nonce . '",'
            . 'signature="' . $this->rsaPrivateSign($message) . '"';
    }

    /**
     * 解析 Authorization 请求头。
     *
     * @return array<string, string>
     */
    private function parseAuthorization(string $authorization): array
    {
        $authorization = trim(str_replace(self::SCHEMA, '', $authorization));
        preg_match_all('/([a-zA-Z0-9_]+)="([^"]*)"/', $authorization, $matches, PREG_SET_ORDER);

        $result = [];
        foreach ($matches as $match) {
            $result[(string) $match[1]] = (string) $match[2];
        }

        return $result;
    }

    /**
     * 使用商户私钥签名。
     */
    private function rsaPrivateSign(string $message): string
    {
        $privateKey = file_get_contents($this->configText('merchant_private_key_path'));
        $resource = $privateKey !== false ? openssl_pkey_get_private($privateKey) : false;
        if (!$resource) {
            throw new LakalaSdkException('拉卡拉商户私钥读取失败');
        }

        $ok = openssl_sign($message, $signature, $resource, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new LakalaSdkException('拉卡拉请求加签失败');
        }

        return base64_encode($signature);
    }

    /**
     * 使用平台证书验签。
     */
    private function rsaPublicVerify(string $message, string $signature): bool
    {
        $cert = file_get_contents($this->configText('platform_cert_path'));
        $resource = $cert !== false ? openssl_pkey_get_public($cert) : false;
        if (!$resource) {
            throw new LakalaSdkException('拉卡拉平台证书读取失败');
        }

        return openssl_verify($message, base64_decode($signature, true) ?: '', $resource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 从商户证书读取序列号。
     */
    private function merchantSerialNo(): string
    {
        $cert = file_get_contents($this->configText('merchant_cert_path'));
        $parsed = $cert !== false ? openssl_x509_parse($cert) : false;
        $serialNo = is_array($parsed) ? (string) ($parsed['serialNumber'] ?? '') : '';
        if ($serialNo === '') {
            throw new LakalaSdkException('拉卡拉商户证书序列号读取失败');
        }

        return $serialNo;
    }

    /**
     * 拼接网关地址。
     */
    private function gatewayUrl(string $path): string
    {
        $custom = $this->configText('api_base_url');
        if ($custom !== '') {
            return rtrim($custom, '/') . '/' . ltrim($path, '/');
        }

        $base = $this->configBool('sandbox') ? 'https://test.wsmsd.cn/sit' : 'https://s2.lakala.com';

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) ($this->config[$key] ?? ''));
    }

    /**
     * 获取布尔配置。
     */
    private function configBool(string $key): bool
    {
        return in_array($this->config[$key] ?? false, [true, 1, '1', 'true', 'on'], true);
    }
}
