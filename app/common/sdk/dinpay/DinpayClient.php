<?php

declare(strict_types=1);

namespace app\common\sdk\dinpay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 智付国密网关客户端。
 */
class DinpayClient
{
    private const GATEWAY = 'https://payment.dinpay.com/trx';
    private const TEST_GATEWAY = 'https://paymenttest.dinpay.com/trx';

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
     * 发起国密接口请求。
     *
     * @param array<string, mixed> $data 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $path, array $data): array
    {
        $this->ensureCryptoAvailable();
        $sm4Key = substr(bin2hex(random_bytes(8)), 0, 16);
        $encryptedKey = base64_encode(hex2bin('04' . $this->sm2Encrypt($sm4Key)));
        $encryptedData = $this->sm4Encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sm4Key);
        $payload = [
            'merchantId' => $this->config['mch_id'],
            'timestamp' => date('YmdHis'),
            'data' => $encryptedData,
            'encryptionKey' => $encryptedKey,
            'signatureMethod' => 'SM3WITHSM2',
            'sign' => $this->sm2Sign($encryptedData),
        ];

        try {
            $response = $this->httpClient->post($this->gateway() . $path, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new DinpaySdkException('智付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result)) {
            throw new DinpaySdkException('智付响应不是合法 JSON');
        }
        if (!in_array((string) ($result['code'] ?? ''), ['0000', '0001'], true)) {
            throw new DinpaySdkException((string) ($result['msg'] ?? '智付请求失败'));
        }
        if (!empty($result['sign']) && !$this->verify((string) ($result['data'] ?? ''), (string) $result['sign'])) {
            throw new DinpaySdkException('智付响应验签失败');
        }

        $data = json_decode((string) ($result['data'] ?? ''), true);
        return is_array($data) ? $data : [];
    }

    /**
     * 校验通知签名。
     */
    public function verify(string $data, string $sign): bool
    {
        $this->ensureCryptoAvailable();
        $sm2 = new \Rtgm\sm\RtSm2('base64', true);
        return (bool) $sm2->verifySign($data, $sign, $this->config['platform_public_key'], '1234567812345678');
    }

    /**
     * 国密签名。
     */
    private function sm2Sign(string $content): string
    {
        $sm2 = new \Rtgm\sm\RtSm2('base64', true);
        return trim($sm2->doSign($content, $this->config['merchant_private_key'], '1234567812345678'));
    }

    /**
     * 国密加密。
     */
    private function sm2Encrypt(string $content): string
    {
        $sm2 = new \Rtgm\sm\RtSm2('base64');
        return $sm2->doEncrypt($content, $this->config['platform_public_key']);
    }

    /**
     * SM4 加密。
     */
    private function sm4Encrypt(string $content, string $key): string
    {
        $sm4 = new \Rtgm\sm\RtSm4($key);
        return $sm4->encrypt($content, 'sm4', base64_decode('T172oxqWwkr8wqB9D7aR7g=='), 'base64');
    }

    /**
     * 检查国密依赖。
     */
    private function ensureCryptoAvailable(): void
    {
        if (!extension_loaded('gmp') || !class_exists(\Rtgm\sm\RtSm2::class) || !class_exists(\Rtgm\sm\RtSm4::class)) {
            throw new DinpaySdkException('智付国密接口需要 GMP 扩展和 Rtgm\\sm 国密库');
        }
    }

    /**
     * 当前网关地址。
     */
    private function gateway(): string
    {
        return $this->config['is_test'] === '1' ? self::TEST_GATEWAY : self::GATEWAY;
    }
}
