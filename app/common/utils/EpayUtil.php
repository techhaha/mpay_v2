<?php
declare(strict_types=1);

namespace app\common\utils;

/**
 * 易支付签名工具（MD5）
 *
 * 规则：
 * - 排除 sign、sign_type
 * - 排除空值（null / ''）
 * - 按字段名 ASCII 升序排序
 * - k=v&...&key=app_secret
 * - MD5 后转小写（兼容大小写比较）
 */
final class EpayUtil
{
    /**
     * 生成签名字符串
     *
     * @param array<string, mixed> $params 请求参数
     */
    public static function make(array $params, string $secret): string
    {
        unset($params['sign'], $params['sign_type']);

        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_string($v) && trim($v) === '') {
                continue;
            }
            $filtered[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }

        ksort($filtered);

        $pairs = [];
        foreach ($filtered as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        $pairs[] = 'key=' . $secret;

        return strtolower(md5(implode('&', $pairs)));
    }

    /**
     * 校验签名
     *
     * @param array<string, mixed> $params
     */
    public static function verify(array $params, string $secret): bool
    {
        $sign = strtolower((string)($params['sign'] ?? ''));
        if ($sign === '') {
            return false;
        }

        return hash_equals(self::make($params, $secret), $sign);
    }
}

