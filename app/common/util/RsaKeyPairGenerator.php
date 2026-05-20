<?php

namespace app\common\util;

use RuntimeException;

/**
 * RSA 密钥对生成器。
 *
 * 统一用于后台自动生成商户 RSA 公私钥对，避免各处重复实现。
 */
final class RsaKeyPairGenerator
{
    /**
     * 生成 RSA 密钥对。
     *
     * @param int $bits 密钥长度
     * @return array{private_key: string, public_key: string}
     */
    public static function generate(int $bits = 2048): array
    {
        while (openssl_error_string()) {
        }

        $configPath = self::resolveOpenSslConfigPath();
        if ($configPath === '') {
            throw new RuntimeException('生成 RSA 密钥对失败，未找到可用的 openssl.cnf 配置文件');
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => max(1024, $bits),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $configPath,
        ]);
        if ($resource === false) {
            throw new RuntimeException('生成 RSA 密钥对失败：' . self::collectOpenSslErrors());
        }

        $privateKey = '';
        if (!openssl_pkey_export($resource, $privateKey, null, ['config' => $configPath]) || trim($privateKey) === '') {
            throw new RuntimeException('导出 RSA 私钥失败：' . self::collectOpenSslErrors());
        }

        $details = openssl_pkey_get_details($resource);
        $publicKey = trim((string) ($details['key'] ?? ''));
        if ($publicKey === '') {
            throw new RuntimeException('导出 RSA 公钥失败');
        }

        return [
            'private_key' => trim($privateKey),
            'public_key' => $publicKey,
        ];
    }

    /**
     * 查找可用的 OpenSSL 配置文件。
     *
     * @return string 配置文件路径
     */
    private static function resolveOpenSslConfigPath(): string
    {
        $candidates = [];

        $projectConfig = base_path(false) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        $candidates[] = $projectConfig;

        $envConfig = trim((string) getenv('OPENSSL_CONF'));
        if ($envConfig !== '') {
            $candidates[] = $envConfig;
        }

        $baseDir = dirname(PHP_BINARY);
        $candidates[] = $baseDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        $candidates[] = $baseDir . DIRECTORY_SEPARATOR . 'openssl.cnf';
        $candidates[] = dirname($baseDir) . DIRECTORY_SEPARATOR . 'Apache2.4.39' . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        $candidates[] = 'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf';
        $candidates[] = 'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf';

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 收集当前 OpenSSL 错误栈。
     *
     * @return string 错误信息
     */
    private static function collectOpenSslErrors(): string
    {
        $messages = [];
        while ($message = openssl_error_string()) {
            $messages[] = $message;
        }

        return $messages ? implode(' | ', $messages) : 'unknown error';
    }
}
