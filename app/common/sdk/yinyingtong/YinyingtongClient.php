<?php

declare(strict_types=1);

namespace app\common\sdk\yinyingtong;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 银盈通 gpayCashApi 客户端。
 */
class YinyingtongClient
{
    private const GATEWAY = 'https://gc-gw.gomepay.com/gpayCashApi';

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
            'verify' => true,
        ]);
    }

    /**
     * 发起接口请求。
     *
     * @param array<string, mixed> $bizData 业务参数
     * @return array<string, mixed>
     */
    public function execute(string $method, array $bizData, string $clientIp, string $device): array
    {
        $payload = [
            'app_id' => $this->config['app_id'],
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'UTF-8',
            'sign_type' => 'MD5',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'client_ip' => $clientIp,
            'data' => json_encode($bizData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'req_no' => $this->requestNo(),
            'terminal_type' => $device === 'pc' ? '3' : '4',
            'browser_brand' => $this->browserBrand($device),
        ];
        $payload['sign'] = $this->signRequest($payload);

        try {
            $response = $this->httpClient->post(self::GATEWAY, [
                'headers' => [
                    'method' => 'cash-api@' . $method,
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (GuzzleException $e) {
            throw new YinyingtongSdkException('银盈通网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new YinyingtongSdkException('银盈通响应不是合法 JSON');
        }

        return $this->parseResponse($data);
    }

    /**
     * 解密银盈通支付通知。
     *
     * @return array<string, mixed>
     */
    public function decryptNotify(string $cipherText): array
    {
        $body = trim(substr($cipherText, 107));
        if (strlen($body) <= 4) {
            throw new YinyingtongSdkException('银盈通通知密文格式错误');
        }

        $cipher = trim(substr($body, 4));
        if ($cipher === '' || strlen($cipher) % 2 !== 0 || !ctype_xdigit($cipher)) {
            throw new YinyingtongSdkException('银盈通通知密文不是合法 HEX');
        }

        $plain = openssl_decrypt(hex2bin($cipher), 'des-ede3-ecb', substr($this->config['product_key'], 0, 8), OPENSSL_RAW_DATA);
        if (!is_string($plain) || $plain === '') {
            throw new YinyingtongSdkException('银盈通通知解密失败');
        }

        $pieces = explode("\x04\x04\x04\x04", rtrim($plain, "\0"));
        $json = trim((string) ($pieces[1] ?? $pieces[0] ?? ''));
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new YinyingtongSdkException('银盈通通知内容不是合法 JSON');
        }

        return $data;
    }

    /**
     * 校验旧通知 JSON 签名。
     *
     * @param array<string, mixed> $payload 通知参数
     */
    public function verify(array $payload): bool
    {
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        ksort($payload);
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'sign' && $value !== '') {
                $pieces[] = $key . '=' . (string) $value;
            }
        }
        $pieces[] = 'key=' . $this->config['app_key'];

        return hash_equals(strtoupper(md5(implode('&', $pieces))), $sign);
    }

    /**
     * 解析银盈通网关响应。
     *
     * @param array<string, mixed> $data 网关响应
     * @return array<string, mixed>
     */
    private function parseResponse(array $data): array
    {
        $code = (string) ($data['code'] ?? '');
        if (in_array($code, ['000000', '900888', '900889', '900001'], true)) {
            $bizData = json_decode((string) ($data['data'] ?? '{}'), true);
            return is_array($bizData) ? $bizData : [];
        }

        if (isset($data['sub_msg'])) {
            throw new YinyingtongSdkException('[' . (string) ($data['sub_code'] ?? '') . ']' . (string) $data['sub_msg']);
        }

        $bizRaw = (string) ($data['data'] ?? '');
        if (str_contains($bizRaw, 'op_ret_code')) {
            $bizData = json_decode($bizRaw, true);
            if (!is_array($bizData)) {
                throw new YinyingtongSdkException('银盈通业务响应不是合法 JSON');
            }

            $opCode = (string) ($bizData['op_ret_code'] ?? '');
            if (in_array($opCode, ['000', '701'], true)) {
                return $bizData;
            }
            if (isset($bizData['op_ret_subcode'])) {
                throw new YinyingtongSdkException('[' . (string) $bizData['op_ret_subcode'] . ']' . (string) ($bizData['op_err_submsg'] ?? ''));
            }

            throw new YinyingtongSdkException('[' . $opCode . ']' . (string) ($bizData['op_ret_msg'] ?? '银盈通业务失败'));
        }

        throw new YinyingtongSdkException((string) ($data['msg'] ?? '银盈通返回数据解析失败'));
    }

    /**
     * 生成请求签名。
     *
     * @param array<string, mixed> $payload 请求参数
     */
    private function signRequest(array $payload): string
    {
        $signKeys = ['req_no', 'app_id', 'sign_type', 'charset', 'format', 'version', 'data', 'timestamp', 'method'];
        ksort($payload);

        $pieces = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, $signKeys, true) && $value !== '') {
                $pieces[] = $key . '=' . (string) $value;
            }
        }
        $pieces[] = 'key=' . $this->config['app_key'];

        return strtoupper(md5(implode('&', $pieces)));
    }

    /**
     * 当前请求号。
     */
    private function requestNo(): string
    {
        return date('YmdHis') . substr(str_replace('.', '', (string) microtime(true)), -6) . random_int(1000, 9999);
    }

    /**
     * 按 MPAY 设备值映射银盈通浏览器品牌。
     */
    private function browserBrand(string $device): string
    {
        return match ($device) {
            'wechat' => '02',
            'alipay' => '01',
            'mobile', 'h5' => '04',
            default => '99',
        };
    }
}
