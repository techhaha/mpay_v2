<?php

declare(strict_types=1);

namespace app\common\util;

use DateTimeInterface;

/**
 * 通用格式化帮助类。
 *
 * 集中处理金额、时间、JSON、映射文案和脱敏逻辑，避免各服务层重复实现。
 */
class FormatHelper
{
    /**
     * 金额格式化，单位为元。
     */
    public static function amount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * 金额格式化，0 时显示不限。
     */
    public static function amountOrUnlimited(int $amount): string
    {
        return $amount > 0 ? self::amount($amount) : '不限';
    }

    /**
     * 次数格式化，0 时显示不限。
     */
    public static function countOrUnlimited(int $count): string
    {
        return $count > 0 ? (string) $count : '不限';
    }

    /**
     * 费率格式化，单位为百分点。
     */
    public static function rate(int $basisPoints): string
    {
        return number_format($basisPoints / 100, 2, '.', '') . '%';
    }

    /**
     * 延迟格式化。
     */
    public static function latency(int $latencyMs): string
    {
        return $latencyMs > 0 ? $latencyMs . ' ms' : '0 ms';
    }

    /**
     * 日期格式化。
     */
    public static function date(mixed $value, string $emptyText = ''): string
    {
        return self::formatTemporalValue($value, 'Y-m-d', $emptyText);
    }

    /**
     * 日期时间格式化。
     */
    public static function dateTime(mixed $value, string $emptyText = ''): string
    {
        return self::formatTemporalValue($value, 'Y-m-d H:i:s', $emptyText);
    }

    /**
     * 按时间戳格式化。
     */
    public static function timestamp(int $timestamp, string $pattern = 'Y-m-d H:i:s', string $emptyText = ''): string
    {
        if ($timestamp <= 0) {
            return $emptyText;
        }

        return date($pattern, $timestamp);
    }

    /**
     * JSON 文本格式化。
     */
    public static function json(mixed $value, string $emptyText = ''): string
    {
        if ($value === null || $value === '' || $value === []) {
            return $emptyText;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                return $encoded !== false ? $encoded : $emptyText;
            }

            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $encoded !== false ? $encoded : $emptyText;
    }

    /**
     * 映射表文本转换。
     */
    public static function textFromMap(int $value, array $map, string $default = '未知'): string
    {
        return (string) ($map[$value] ?? $default);
    }

    /**
     * 接口凭证明文脱敏。
     */
    public static function maskCredentialValue(string $credentialValue, bool $maskShortValue = true): string
    {
        $credentialValue = trim($credentialValue);
        if ($credentialValue === '') {
            return '';
        }

        $length = strlen($credentialValue);
        if ($length <= 8) {
            return $maskShortValue ? str_repeat('*', $length) : $credentialValue;
        }

        return substr($credentialValue, 0, 4) . '****' . substr($credentialValue, -4);
    }

    /**
     * 将模型或对象归一化成数组。
     */
    public static function normalizeModel(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                $data = $value->toArray();
                return is_array($data) ? $data : null;
            }

            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return null;
            }

            $data = json_decode($json, true);
            return is_array($data) ? $data : null;
        }

        return null;
    }

    /**
     * 统一格式化时间值。
     */
    private static function formatTemporalValue(mixed $value, string $pattern, string $emptyText): string
    {
        if ($value === null || $value === '') {
            return $emptyText;
        }

        if (is_string($value)) {
            $text = trim($value);
            return $text === '' ? $emptyText : $text;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format($pattern);
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format($pattern);
        }

        return (string) $value;
    }
}
