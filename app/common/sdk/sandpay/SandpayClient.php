<?php

declare(strict_types=1);

namespace app\common\sdk\sandpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 杉德开放平台轻量客户端。
 *
 * 迁移自彩虹 `SandpayClient`：AES 加密业务报文、RSA 加密 AES 密钥、
 * RSA-SHA256 签名/验签，并解密响应 `bizData`。
 */
class SandpayClient
{
    private const VERSION = '4.0.0';
    private const SIGN_TYPE = 'RSA';
    private const ENCRYPT_TYPE = 'AES';
    private const PROD_GATEWAY = 'https://openapi.sandpay.com.cn';
    private const TEST_GATEWAY = 'https://openapi-uat01.sand.com.cn';

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
     * 公钥资源。
     *
     * @var mixed
     */
    private mixed $publicKey;

    /**
     * 私钥资源。
     *
     * @var mixed
     */
    private mixed $privateKey;

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
        $this->publicKey = $this->loadPublicKey();
        $this->privateKey = $this->loadPrivateKey();
    }

    /**
     * 执行杉德接口请求。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $params 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $params): array
    {
        ksort($params);
        $plain = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($plain)) {
            throw new SandpaySdkException('杉德请求报文编码失败');
        }

        $aesKey = bin2hex(random_bytes(8));
        $bizData = $this->aesEncrypt($plain, $aesKey);
        $payload = [
            'accessMid' => $this->configText('merchant_no'),
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => self::VERSION,
            'signType' => self::SIGN_TYPE,
            'encryptType' => self::ENCRYPT_TYPE,
            'encryptKey' => $this->rsaPublicEncrypt($aesKey),
            'bizData' => $bizData,
        ];
        $payload['sign'] = $this->rsaPrivateSign($bizData);

        try {
            $response = $this->httpClient->post($this->gatewayUrl() . $path, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new SandpaySdkException('杉德网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new SandpaySdkException('杉德响应不是合法 JSON');
        }
        if ((string) ($result['respCode'] ?? '') === 'fail') {
            throw new SandpaySdkException((string) ($result['respDesc'] ?? '杉德请求失败'));
        }
        if ((string) ($result['respCode'] ?? '') !== 'success') {
            throw new SandpaySdkException('杉德响应解析失败');
        }
        if (!$this->verify((string) ($result['bizData'] ?? ''), (string) ($result['sign'] ?? ''))) {
            throw new SandpaySdkException('杉德响应验签失败');
        }

        $plainText = $this->aesDecrypt(
            (string) $result['bizData'],
            $this->rsaPrivateDecrypt((string) $result['encryptKey'])
        );
        $data = json_decode($plainText, true);
        if (!is_array($data)) {
            throw new SandpaySdkException('杉德业务响应解析失败');
        }
        if ((string) ($data['resultStatus'] ?? '') === 'fail') {
            throw new SandpaySdkException('[' . (string) ($data['errorCode'] ?? '') . ']' . (string) ($data['errorDesc'] ?? '杉德业务失败'));
        }

        return $data;
    }

    /**
     * 验证杉德通知签名。
     */
    public function verify(string $bizData, string $sign): bool
    {
        if ($bizData === '' || $sign === '') {
            return false;
        }

        return openssl_verify($bizData, base64_decode($sign), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * AES-128-ECB 加密。
     */
    private function aesEncrypt(string $data, string $key): string
    {
        $encrypted = openssl_encrypt($data, 'AES-128-ECB', $key);
        if (!is_string($encrypted)) {
            throw new SandpaySdkException('杉德 AES 加密失败');
        }

        return $encrypted;
    }

    /**
     * AES-128-ECB 解密。
     */
    private function aesDecrypt(string $data, string $key): string
    {
        $decrypted = openssl_decrypt($data, 'AES-128-ECB', $key);
        if (!is_string($decrypted)) {
            throw new SandpaySdkException('杉德 AES 解密失败');
        }

        return $decrypted;
    }

    /**
     * RSA-SHA256 私钥签名。
     */
    private function rsaPrivateSign(string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new SandpaySdkException('杉德请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * RSA 公钥加密 AES 密钥。
     */
    private function rsaPublicEncrypt(string $data): string
    {
        $encrypted = '';
        if (!openssl_public_encrypt($data, $encrypted, $this->publicKey, OPENSSL_PKCS1_PADDING)) {
            throw new SandpaySdkException('杉德 AES 密钥加密失败');
        }

        return base64_encode($encrypted);
    }

    /**
     * RSA 私钥解密 AES 密钥。
     */
    private function rsaPrivateDecrypt(string $data): string
    {
        $decrypted = '';
        if (!openssl_private_decrypt(base64_decode($data), $decrypted, $this->privateKey, OPENSSL_PKCS1_PADDING)) {
            throw new SandpaySdkException('杉德 AES 密钥解密失败');
        }

        return $decrypted;
    }

    /**
     * 加载杉德公钥证书。
     *
     * @return mixed
     */
    private function loadPublicKey(): mixed
    {
        $content = file_get_contents($this->configText('public_cert_path'));
        if (!is_string($content) || $content === '') {
            throw new SandpaySdkException('杉德公钥证书读取失败');
        }

        $cert = str_contains($content, 'BEGIN')
            ? $content
            : "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($content), 64, "\n") . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey === false) {
            throw new SandpaySdkException('从杉德公钥证书获取公钥失败');
        }

        return $publicKey;
    }

    /**
     * 加载商户 PFX 私钥证书。
     *
     * @return mixed
     */
    private function loadPrivateKey(): mixed
    {
        $content = file_get_contents($this->configText('private_cert_path'));
        if (!is_string($content) || $content === '') {
            throw new SandpaySdkException('杉德商户私钥证书读取失败');
        }
        if (!openssl_pkcs12_read($content, $certs, $this->configText('private_cert_password'))) {
            throw new SandpaySdkException('杉德商户私钥证书解析失败');
        }

        $privateKey = openssl_pkey_get_private((string) ($certs['pkey'] ?? ''));
        if ($privateKey === false) {
            throw new SandpaySdkException('杉德商户私钥获取失败');
        }

        return $privateKey;
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
