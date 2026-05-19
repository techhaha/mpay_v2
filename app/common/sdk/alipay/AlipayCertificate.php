<?php

declare(strict_types=1);

namespace app\common\sdk\alipay;

/**
 * 支付宝证书模式工具。
 *
 * 证书模式调用 OpenAPI 时，请求公共参数需要带上：
 * - app_cert_sn：应用公钥证书序列号。
 * - alipay_root_cert_sn：支付宝根证书序列号，多个 RSA 根证书用下划线拼接。
 *
 * 同时，验签支付宝返回或异步通知时，需要从支付宝公钥证书中提取公钥。
 */
class AlipayCertificate
{
    /**
     * 计算应用公钥证书序列号。
     *
     * @param string $certContent 应用公钥证书内容
     * @return string 证书序列号
     */
    public static function appCertSn(string $certContent): string
    {
        return self::certSn($certContent);
    }

    /**
     * 计算支付宝根证书序列号。
     *
     * 支付宝根证书文件可能包含多段证书，只有 RSA 证书参与序列号拼接。
     *
     * @param string $rootCertContent 支付宝根证书内容
     * @return string 根证书序列号
     */
    public static function alipayRootCertSn(string $rootCertContent): string
    {
        $serialNumbers = [];
        foreach (self::splitCertificates($rootCertContent) as $certContent) {
            $parsed = self::parse($certContent);
            $signatureType = (string) ($parsed['signatureTypeSN'] ?? '');
            if (stripos($signatureType, 'RSA') === false) {
                continue;
            }

            $serialNumbers[] = self::serialNumber($parsed);
        }

        if ($serialNumbers === []) {
            throw new AlipaySdkException('支付宝根证书中未找到 RSA 证书');
        }

        return implode('_', $serialNumbers);
    }

    /**
     * 从支付宝公钥证书中提取公钥。
     *
     * @param string $certContent 支付宝公钥证书内容
     * @return string PEM 公钥
     */
    public static function publicKeyFromCert(string $certContent): string
    {
        $resource = openssl_pkey_get_public($certContent);
        if ($resource === false) {
            throw new AlipaySdkException('支付宝公钥证书无效');
        }

        $details = openssl_pkey_get_details($resource);
        $key = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($key === '') {
            throw new AlipaySdkException('从支付宝公钥证书提取公钥失败');
        }

        return $key;
    }

    /**
     * 计算单个证书序列号。
     *
     * @param string $certContent 证书内容
     * @return string 证书序列号
     */
    private static function certSn(string $certContent): string
    {
        return self::serialNumber(self::parse($certContent));
    }

    /**
     * 解析 X509 证书。
     *
     * @param string $certContent 证书内容
     * @return array<string, mixed> 证书解析结果
     */
    private static function parse(string $certContent): array
    {
        $parsed = openssl_x509_parse($certContent);
        if (!is_array($parsed)) {
            throw new AlipaySdkException('支付宝证书解析失败');
        }

        return $parsed;
    }

    /**
     * 按支付宝规则计算证书序列号。
     *
     * @param array<string, mixed> $parsed 证书解析结果
     * @return string 证书序列号
     */
    private static function serialNumber(array $parsed): string
    {
        $issuer = $parsed['issuer'] ?? null;
        if (!is_array($issuer)) {
            throw new AlipaySdkException('支付宝证书缺少颁发者信息');
        }

        $serialNumber = self::certificateSerialNumber($parsed);
        if ($serialNumber === '') {
            throw new AlipaySdkException('支付宝证书缺少序列号');
        }

        return md5(self::issuerString($issuer) . $serialNumber);
    }

    /**
     * 获取证书十进制序列号。
     *
     * OpenSSL 在不同环境下可能把大整数序列号放在 serialNumberHex 中，这里统一转成支付宝
     * 证书 SN 规则需要的十进制字符串。
     *
     * @param array<string, mixed> $parsed 证书解析结果
     * @return string 十进制序列号
     */
    private static function certificateSerialNumber(array $parsed): string
    {
        $serialNumber = trim((string) ($parsed['serialNumber'] ?? ''));
        if ($serialNumber !== '' && preg_match('/^\d+$/', $serialNumber)) {
            return $serialNumber;
        }

        $serialNumberHex = preg_replace('/[^0-9a-f]/i', '', (string) ($parsed['serialNumberHex'] ?? '')) ?? '';
        if ($serialNumberHex !== '') {
            return self::hexToDecimal($serialNumberHex);
        }

        if ($serialNumber !== '' && ctype_xdigit($serialNumber)) {
            return self::hexToDecimal($serialNumber);
        }

        return $serialNumber;
    }

    /**
     * 生成证书颁发者字符串。
     *
     * @param array<string, mixed> $issuer 颁发者信息
     * @return string 颁发者字符串
     */
    private static function issuerString(array $issuer): string
    {
        $parts = [];
        foreach (array_reverse($issuer, true) as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode(',', $parts);
    }

    /**
     * 将十六进制大整数转成十进制字符串。
     *
     * 这里不用 bcmath，避免部署环境没有安装扩展时证书模式不可用。
     *
     * @param string $hex 十六进制字符串
     * @return string 十进制字符串
     */
    private static function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }

        $decimal = '0';
        foreach (str_split($hex) as $char) {
            $decimal = self::decimalAdd(self::decimalMultiply($decimal, 16), hexdec($char));
        }

        return $decimal;
    }

    /**
     * 十进制字符串乘以较小整数。
     *
     * @param string $decimal 十进制字符串
     * @param int $multiplier 乘数
     * @return string 计算结果
     */
    private static function decimalMultiply(string $decimal, int $multiplier): string
    {
        $carry = 0;
        $result = '';

        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $number = ((int) $decimal[$i]) * $multiplier + $carry;
            $result = (string) ($number % 10) . $result;
            $carry = intdiv($number, 10);
        }

        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    /**
     * 十进制字符串加上较小整数。
     *
     * @param string $decimal 十进制字符串
     * @param int $addend 加数
     * @return string 计算结果
     */
    private static function decimalAdd(string $decimal, int $addend): string
    {
        $carry = $addend;
        $result = '';

        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $number = ((int) $decimal[$i]) + $carry;
            $result = (string) ($number % 10) . $result;
            $carry = intdiv($number, 10);
        }

        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    /**
     * 拆分根证书文件中的多段证书。
     *
     * @param string $content 证书文件内容
     * @return array<int, string> 证书列表
     */
    private static function splitCertificates(string $content): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $content, $matches);

        return array_values(array_filter(array_map('trim', $matches[0] ?? [])));
    }
}
