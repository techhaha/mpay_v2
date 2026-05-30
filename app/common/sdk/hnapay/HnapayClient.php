<?php

declare(strict_types=1);

namespace app\common\sdk\hnapay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 新生支付网关客户端。
 */
class HnapayClient
{
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
            'verify' => true,
        ]);
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    public function scanPay(array $payload): array
    {
        $params = array_merge([
            'tranCode' => 'WS01',
            'version' => '2.1',
            'merId' => $this->config['mer_id'],
            'payType' => 'QRCODE_B2C',
            'charset' => '1',
            'signType' => '1',
        ], $payload);
        $signOrder = ['tranCode', 'version', 'merId', 'submitTime', 'merOrderNum', 'tranAmt', 'payType', 'orgCode', 'notifyUrl', 'charset', 'signType'];
        $params['signMsg'] = $this->sign($params, $signOrder, true);

        $data = $this->postJson('https://gateway.hnapay.com/website/scanPay.do', $params);
        if ((string) ($data['resultCode'] ?? '') !== '0000') {
            throw new HnapaySdkException('[' . (string) ($data['resultCode'] ?? '') . ']' . (string) ($data['msgExt'] ?? '新生扫码下单失败'));
        }
        if (!empty($data['signMsg'])) {
            $verifyOrder = ['tranCode', 'version', 'merId', 'merOrderNum', 'tranAmt', 'submitTime', 'qrCodeUrl', 'hnapayOrderId', 'resultCode', 'charset', 'signType'];
            if (!$this->verify($data, $verifyOrder, (string) $data['signMsg'], true)) {
                throw new HnapaySdkException('新生扫码响应验签失败');
            }
        }
        $data['qrCodeUrl'] = $this->extractQrContent((string) ($data['qrCodeUrl'] ?? ''));

        return $data;
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $payload 业务参数
     * @return array<string, mixed>
     */
    public function jsapiPay(string $payNo, array $payload): array
    {
        $params = [
            'version' => '2.0',
            'tranCode' => 'ITA10',
            'merId' => $this->config['mer_id'],
            'merOrderId' => $payNo,
            'submitTime' => substr($payNo, 3, 14) ?: date('YmdHis'),
            'signType' => '1',
            'charset' => '1',
            'msgCiphertext' => $this->encryptPayload($payload),
        ];
        $params['signValue'] = $this->sign($params, ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext']);

        $data = $this->postJson('https://gateway.hnapay.com/ita/inCharge.do', $params);
        if ((string) ($data['resultCode'] ?? '') !== '0000') {
            throw new HnapaySdkException('[' . (string) ($data['errorCode'] ?? '') . ']' . (string) ($data['errorMsg'] ?? '新生JSAPI下单失败'));
        }

        return $data;
    }

    /**
     * 支付宝 H5 表单。
     *
     * @param array<string, mixed> $payload 业务参数
     */
    public function h5Html(string $payNo, array $payload): string
    {
        $url = 'https://gateway.hnapay.com/multipay/h5.do';
        $params = [
            'version' => '2.0',
            'tranCode' => 'MUP11',
            'merId' => $this->config['mer_id'],
            'merOrderId' => $payNo,
            'submitTime' => substr($payNo, 3, 14) ?: date('YmdHis'),
            'signType' => '1',
            'charset' => '1',
            'msgCiphertext' => $this->encryptPayload($payload),
        ];
        $params['signValue'] = $this->sign($params, ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'signType', 'charset', 'msgCiphertext']);

        $html = "<form id='hnapaysubmit' name='hnapaysubmit' action='{$url}' method='POST'>";
        foreach ($params as $key => $value) {
            $html .= "<input type='hidden' name='{$key}' value='" . htmlentities((string) $value, ENT_QUOTES | ENT_HTML5) . "'/>";
        }
        $html .= "<input type='submit' value='ok' style='display:none;'></form><script>document.forms['hnapaysubmit'].submit();</script>";

        return $html;
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $payload 业务参数
     * @return array<string, mixed>
     */
    public function refund(string $payNo, array $payload): array
    {
        $params = [
            'version' => '2.0',
            'tranCode' => 'EXP09',
            'merId' => $this->config['mer_id'],
            'merOrderId' => $payNo,
            'submitTime' => date('YmdHis'),
            'signType' => '1',
            'charset' => '1',
            'msgCiphertext' => $this->encryptPayload($payload),
        ];
        $params['signValue'] = $this->sign($params, ['version', 'tranCode', 'merId', 'merOrderId', 'submitTime', 'msgCiphertext']);

        $data = $this->postJson('https://gateway.hnapay.com/exp/refund.do', $params);
        if ((string) ($data['resultCode'] ?? '') !== '0000') {
            throw new HnapaySdkException('[' . (string) ($data['errorCode'] ?? '') . ']' . (string) ($data['errorMsg'] ?? '新生退款失败'));
        }

        return $data;
    }

    /**
     * 校验 JSAPI/H5 通知。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verifyPayNotify(array $payload): bool
    {
        if ((string) ($payload['tranCode'] ?? '') === 'MUP11') {
            return $this->verify($payload, ['version', 'tranCode', 'merOrderId', 'merId', 'charset', 'signType', 'resultCode', 'hnapayOrderId'], (string) ($payload['signValue'] ?? ''));
        }

        return $this->verify($payload, ['version', 'tranCode', 'merOrderId', 'merId', 'merAttach', 'charset', 'signType', 'hnapayOrderId', 'resultCode', 'tranAmt', 'submitTime', 'tranFinishTime'], (string) ($payload['signValue'] ?? ''));
    }

    /**
     * 校验扫码通知。
     *
     * @param array<string, mixed> $payload 参数
     */
    public function verifyScanNotify(array $payload): bool
    {
        return $this->verify($payload, ['tranCode', 'version', 'merId', 'merOrderNum', 'tranAmt', 'submitTime', 'hnapayOrderId', 'tranFinishTime', 'respCode', 'charset', 'signType'], (string) ($payload['signMsg'] ?? ''), true);
    }

    /**
     * 表单提交并解析 JSON。
     *
     * @param array<string, mixed> $params 参数
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $params): array
    {
        try {
            $response = $this->httpClient->post($url, ['form_params' => $params]);
        } catch (GuzzleException $e) {
            throw new HnapaySdkException('新生支付网关请求失败：' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new HnapaySdkException('新生支付响应不是合法 JSON');
        }

        return $data;
    }

    /**
     * 业务参数 RSA 加密。
     *
     * @param array<string, mixed> $payload 参数
     */
    private function encryptPayload(array $payload): string
    {
        $publicKey = openssl_get_publickey($this->formatPublicKey($this->config['platform_public_key']));
        if (!$publicKey) {
            throw new HnapaySdkException('新生支付平台公钥不正确');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encrypted = '';
        foreach (str_split((string) $json, 117) as $chunk) {
            if (!openssl_public_encrypt($chunk, $part, $publicKey)) {
                throw new HnapaySdkException('新生支付业务参数加密失败');
            }
            $encrypted .= $part;
        }

        return base64_encode($encrypted);
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $payload 参数
     * @param array<int, string> $keys 签名字段
     */
    private function sign(array $payload, array $keys, bool $hex = false): string
    {
        if (!openssl_sign($this->signContent($payload, $keys), $signature, $this->formatPrivateKey($this->config['merchant_private_key']), OPENSSL_ALGO_SHA1)) {
            throw new HnapaySdkException('新生支付请求签名失败');
        }

        return $hex ? bin2hex($signature) : base64_encode($signature);
    }

    /**
     * 校验签名。
     *
     * @param array<string, mixed> $payload 参数
     * @param array<int, string> $keys 签名字段
     */
    private function verify(array $payload, array $keys, string $signature, bool $hex = false): bool
    {
        if ($signature === '') {
            return false;
        }
        $sign = $hex ? hex2bin($signature) : base64_decode($signature);
        if ($sign === false) {
            return false;
        }

        return openssl_verify($this->signContent($payload, $keys), $sign, $this->formatPublicKey($this->config['platform_public_key'])) === 1;
    }

    /**
     * 待签名字符串。
     *
     * @param array<string, mixed> $payload 参数
     * @param array<int, string> $keys 字段顺序
     */
    private function signContent(array $payload, array $keys): string
    {
        $pieces = [];
        foreach ($keys as $key) {
            $value = $payload[$key] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $pieces[] = $key . '=[' . $value . ']';
        }

        return implode('', $pieces);
    }

    /**
     * 提取二维码原文。
     */
    private function extractQrContent(string $url): string
    {
        if (!str_contains($url, 'qrContent=')) {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);
        return (string) ($params['qrContent'] ?? $url);
    }

    /**
     * PEM 格式化商户私钥。
     */
    private function formatPrivateKey(string $key): string
    {
        return str_contains($key, 'BEGIN') ? $key : "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * PEM 格式化平台公钥。
     */
    private function formatPublicKey(string $key): string
    {
        return str_contains($key, 'BEGIN') ? $key : "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }
}
