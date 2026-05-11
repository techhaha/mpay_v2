<?php

declare(strict_types=1);

namespace app\service\payment\epay;

use app\common\constant\AuthConstant;
use app\exception\PaymentException;

/**
 * ePay 签名器管理器。
 *
 * 负责根据签名类型分发 MD5 与 RSA 实现。
 */
class EpaySignerManager
{
    public function __construct(
        private readonly Md5Signer $md5Signer,
        private readonly RsaSigner $rsaSigner
    ) {
    }

    /**
     * 生成签名。
     *
     * @param array<string, mixed> $params 待签名参数
     * @param string $signType 签名类型
     * @param string $key 密钥
     * @return string 签名结果
     */
    public function sign(array $params, string $signType, string $key): string
    {
        return match (strtoupper(trim($signType))) {
            AuthConstant::API_SIGN_NAME_MD5 => $this->md5Signer->sign($params, $key),
            AuthConstant::API_SIGN_NAME_RSA => $this->rsaSigner->sign($params, $key),
            default => throw new PaymentException('不支持的签名类型', 40200),
        };
    }

    /**
     * 验证签名。
     *
     * @param array<string, mixed> $params 待验签参数
     * @param string $signType 签名类型
     * @param string $sign 签名值
     * @param string $key 密钥
     * @return bool 是否通过
     */
    public function verify(array $params, string $signType, string $sign, string $key): bool
    {
        return match (strtoupper(trim($signType))) {
            AuthConstant::API_SIGN_NAME_MD5 => $this->md5Signer->verify($params, $sign, $key),
            AuthConstant::API_SIGN_NAME_RSA => $this->rsaSigner->verify($params, $sign, $key),
            default => false,
        };
    }
}
