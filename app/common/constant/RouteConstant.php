<?php

namespace app\common\constant;

/**
 * 路由与通道编排枚举。
 *
 * 用于描述通道类型、通道模式以及轮询组的路由策略。
 */
final class RouteConstant
{
    /**
     * 平台代收通道类型。
     */
    public const CHANNEL_TYPE_PLATFORM_COLLECT = 0;

    /**
     * 商户自收通道类型。
     */
    public const CHANNEL_TYPE_MERCHANT_SELF = 1;

    /**
     * 代收通道模式，资金直接进入平台侧。
     */
    public const CHANNEL_MODE_COLLECT = 0;

    /**
     * 自收通道模式，资金直接进入商户侧。
     */
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

    /**
     * 获取通道类型名称映射。
     *
     * @return array<int, string> 通道类型名称表
     */
    public static function channelTypeMap(): array
    {
        return [
            self::CHANNEL_TYPE_PLATFORM_COLLECT => '平台代收',
            self::CHANNEL_TYPE_MERCHANT_SELF => '商户自收',
        ];
    }

    /**
     * 获取通道模式名称映射。
     *
     * @return array<int, string> 通道模式名称表
     */
    public static function channelModeMap(): array
    {
        return [
            self::CHANNEL_MODE_COLLECT => '代收',
            self::CHANNEL_MODE_SELF => '自收',
        ];
    }

    /**
     * 获取路由模式名称映射。
     *
     * @return array<int, string> 路由模式名称表
     */
    public static function routeModeMap(): array
    {
        return [
            self::ROUTE_MODE_ORDER => '顺序依次轮询',
            self::ROUTE_MODE_WEIGHTED => '权重随机轮询',
            self::ROUTE_MODE_FIRST_AVAILABLE => '默认启用通道',
        ];
    }
}


