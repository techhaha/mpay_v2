<?php

declare(strict_types=1);

namespace app\service\payment\epay;

/**
 * ePay 签名器抽象基类。
 *
 * 负责公共签名原文拼装与 PEM 密钥归一化。
 */
abstract class EpaySignerAbstract
{
    /**
     * 构造待签名原文。
     *
     * @param array<string, mixed> $params 待签名参数
     * @return string 签名原文
     */
    protected function buildContent(array $params): string
    {
        ksort($params);
        $parts = [];

        foreach ($params as $key => $value) {
            if ($key === 'sign' || $key === 'sign_type') {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $key . '=' . (string) $value;
        }

        return implode('&', $parts);
    }

    /**
     * 归一化 PEM 密钥。
     *
     * @param string $key 原始密钥
     * @param string $type 密钥类型
     * @return string PEM 格式密钥
     */
    protected function normalizePem(string $key, string $type): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        if (str_contains($key, 'BEGIN ')) {
            return $key;
        }

        $type = strtoupper(trim($type));
        $body = preg_replace('/\s+/', '', $key) ?? $key;

        return sprintf(
            "-----BEGIN %s KEY-----\n%s\n-----END %s KEY-----",
            $type,
            trim(chunk_split($body, 64, "\n")),
            $type
        );
    }
}
