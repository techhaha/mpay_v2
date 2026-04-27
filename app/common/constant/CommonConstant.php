<?php

namespace app\common\constant;

/**
 * 通用状态枚举。
 */
final class CommonConstant
{
    /**
     * 禁用状态。
     */
    public const STATUS_DISABLED = 0;

    /**
     * 启用状态。
     */
    public const STATUS_ENABLED = 1;

    /**
     * 否，通常用于布尔类字段的数值表示。
     */
    public const NO = 0;

    /**
     * 是，通常用于布尔类字段的数值表示。
     */
    public const YES = 1;

    /**
     * 获取状态名称映射。
     *
     * @return array<int, string> 状态名称表
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用',
        ];
    }

    /**
     * 获取是否名称映射。
     *
     * @return array<int, string> 是否名称表
     */
    public static function yesNoMap(): array
    {
        return [
            self::NO => '否',
            self::YES => '是',
        ];
    }
}



