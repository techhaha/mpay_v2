<?php

namespace app\common\constant;

/**
 * 支付插件类型常量。
 *
 * 类型用于管理后台和商户后台筛选展示，具体支付、监听和路由行为仍由插件契约决定。
 */
final class PaymentPluginTypeConstant
{
    /**
     * 直连支付插件。
     */
    public const TYPE_DIRECT = 1;

    /**
     * 挂机监听插件。
     */
    public const TYPE_HANGUP = 2;

    /**
     * 后台监听插件。
     */
    public const TYPE_BACKEND = 3;

    /**
     * 获取插件类型名称映射。
     *
     * @return array<int, string> 插件类型名称表
     */
    public static function typeMap(): array
    {
        return [
            self::TYPE_DIRECT => '直连支付插件',
            self::TYPE_HANGUP => '挂机监听插件',
            self::TYPE_BACKEND => '后台监听插件',
        ];
    }

    /**
     * 判断插件类型是否有效。
     *
     * @param int $type 插件类型
     * @return bool 是否有效
     */
    public static function isValid(int $type): bool
    {
        return array_key_exists($type, self::typeMap());
    }

    /**
     * 获取插件类型名称。
     *
     * @param int $type 插件类型
     * @return string 类型名称
     */
    public static function label(int $type): string
    {
        return self::typeMap()[$type] ?? '未知';
    }
}
