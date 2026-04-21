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
     *
     * @param int $amount 金额（分）
     * @return string 格式化后的金额字符串
     */
    public static function amount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    /**
     * 金额格式化，0 时显示不限。
     *
     * @param int $amount 金额（分）
     * @return string 格式化后的金额字符串
     */
    public static function amountOrUnlimited(int $amount): string
    {
        return $amount > 0 ? self::amount($amount) : '不限';
    }

    /**
     * 次数格式化，0 时显示不限。
     *
     * @param int $count 次数
     * @return string 格式化后的次数字符串
     */
    public static function countOrUnlimited(int $count): string
    {
        return $count > 0 ? (string) $count : '不限';
    }

    /**
     * 费率格式化，单位为百分点。
     *
     * @param int $basisPoints 基点值
     * @return string 格式化后的费率字符串
     */
    public static function rate(int $basisPoints): string
    {
        return number_format($basisPoints / 100, 2, '.', '') . '%';
    }

    /**
     * 延迟格式化。
     *
     * @param int $latencyMs 延迟毫秒数
     * @return string 格式化后的延迟字符串
     */
    public static function latency(int $latencyMs): string
    {
        return $latencyMs > 0 ? $latencyMs . ' ms' : '0 ms';
    }

    /**
     * 日期格式化。
     *
     * @param mixed $value 日期值
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的日期字符串
     */
    public static function date(mixed $value, string $emptyText = ''): string
    {
        return self::formatTemporalValue($value, 'Y-m-d', $emptyText);
    }

    /**
     * 日期时间格式化。
     *
     * @param mixed $value 日期时间值
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的日期时间字符串
     */
    public static function dateTime(mixed $value, string $emptyText = ''): string
    {
        return self::formatTemporalValue($value, 'Y-m-d H:i:s', $emptyText);
    }

    /**
     * 按时间戳格式化。
     *
     * @param int $timestamp Unix 时间戳
     * @param string $pattern 输出格式
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的时间字符串
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
     *
     * @param mixed $value JSON 值
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的 JSON 文本
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
     *
     * @param int $value 待映射值
     * @param array<int, string> $map 映射表
     * @param string $default 默认值
     * @return string 映射后的文本
     */
    public static function textFromMap(int $value, array $map, string $default = '未知'): string
    {
        return (string) ($map[$value] ?? $default);
    }

    /**
     * 接口凭证明文脱敏。
     *
     * @param string $credentialValue 凭证原文
     * @param bool $maskShortValue 是否对短值也进行脱敏
     * @return string 脱敏后的文本
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
     *
     * @param mixed $value 模型、对象或数组
     * @return array|null 归一化后的数组
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
     *
     * @param mixed $value 时间值
     * @param string $pattern 输出格式
     * @param string $emptyText 为空时显示文案
     * @return string 格式化后的时间文本
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

