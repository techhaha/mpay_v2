<?php

declare(strict_types=1);

namespace app\common\sdk\kuaiqian;

/**
 * 快钱人民币网关/H5 表单支付轻量客户端。
 */
class KuaiqianClient
{
    /**
     * SDK 配置。
     *
     * @var array<string, string>
     */
    private array $config;

    /**
     * 构造方法。
     *
     * @param array<string, string> $config SDK 配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * 构造自动提交表单。
     *
     * @param string $url 网关地址
     * @param array<string, mixed> $params 表单参数
     */
    public function formHtml(string $url, array $params): string
    {
        $params['signMsg'] = $this->sign($params);
        $html = '<form action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" method="post" id="dopay">';
        foreach ($params as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }
        $html .= '</form><script>document.getElementById("dopay").submit();</script>';

        return $html;
    }

    /**
     * 校验快钱人民币网关回调签名。
     *
     * @param array<string, mixed> $payload 回调参数
     */
    public function verifyNotify(array $payload): bool
    {
        $sign = (string) ($payload['signMsg'] ?? '');
        if ($sign === '') {
            return false;
        }

        $order = ['merchantAcctId', 'version', 'language', 'signType', 'payType', 'bankId', 'orderId', 'orderTime', 'orderAmount', 'bindCard', 'bindMobile', 'dealId', 'bankDealId', 'dealTime', 'payAmount', 'fee', 'ext1', 'ext2', 'payResult', 'aggregatePay', 'errCode', 'period'];
        $pieces = [];
        foreach ($order as $key) {
            if (($payload[$key] ?? '') !== '') {
                $pieces[] = $key . '=' . (string) $payload[$key];
            }
        }

        return $this->verify(implode('&', $pieces), $sign);
    }

    /**
     * 商户私钥签名。
     *
     * @param array<string, mixed> $payload 表单参数
     */
    private function sign(array $payload): string
    {
        $pieces = [];
        foreach ($payload as $key => $value) {
            if ($key !== 'signMsg' && $value !== '') {
                $pieces[] = $key . '=' . (string) $value;
            }
        }

        $pfx = file_get_contents($this->config['merchant_key_path']);
        if ($pfx === false || !openssl_pkcs12_read($pfx, $cert, $this->config['merchant_cert_password'])) {
            throw new KuaiqianSdkException('快钱商户私钥证书解析失败');
        }
        $privateKey = openssl_pkey_get_private($cert['pkey']);
        if ($privateKey === false) {
            throw new KuaiqianSdkException('快钱商户私钥读取失败');
        }

        $signature = '';
        if (!openssl_sign(implode('&', $pieces), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new KuaiqianSdkException('快钱请求签名失败');
        }

        return base64_encode($signature);
    }

    /**
     * 平台公钥验签。
     */
    private function verify(string $content, string $sign): bool
    {
        $cert = file_get_contents($this->config['platform_cert_path']);
        if ($cert === false) {
            throw new KuaiqianSdkException('快钱平台证书读取失败');
        }
        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey === false) {
            throw new KuaiqianSdkException('快钱平台证书公钥解析失败');
        }

        return openssl_verify($content, base64_decode($sign), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
