<?php

namespace app\common\constant;

/**
 * 路由与通道编排相关枚举。
 */
final class RouteConstant
{
    public const CHANNEL_TYPE_PLATFORM_COLLECT = 0;
    public const CHANNEL_TYPE_MERCHANT_SELF = 1;

    public const CHANNEL_MODE_COLLECT = 0;
    public const CHANNEL_MODE_SELF = 1;

    /**
     * 轮询组模式：按编排顺序依次轮询可用通道。
     */
    public const ROUTE_MODE_ORDER = 0;

    /**
     * 轮询组模式：按通道权重随机选择可用通道。
     */
    public const ROUTE_MODE_WEIGHTED = 1;

    /**
     * 轮询组模式：优先选择默认启用通道，默认不可用时回退到首个可用通道。
     */
    public const ROUTE_MODE_FIRST_AVAILABLE = 2;

    public static function channelTypeMap(): array
    {
        return [
            self::CHANNEL_TYPE_PLATFORM_COLLECT => '平台代收',
            self::CHANNEL_TYPE_MERCHANT_SELF => '商户自有',
        ];
    }

    public static function channelModeMap(): array
    {
        return [
            self::CHANNEL_MODE_COLLECT => '代收',
            self::CHANNEL_MODE_SELF => '自收',
        ];
    }

    public static function routeModeMap(): array
    {
        return [
            self::ROUTE_MODE_ORDER => '顺序依次轮询',
            self::ROUTE_MODE_WEIGHTED => '权重随机轮询',
            self::ROUTE_MODE_FIRST_AVAILABLE => '默认启用通道',
        ];
    }
}
