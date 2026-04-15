<?php

namespace app\common\constant;

/**
 * 通用状态常量。
 */
final class CommonConstant
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    public const NO = 0;
    public const YES = 1;

    public static function statusMap(): array
    {
        return [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用',
        ];
    }

    public static function yesNoMap(): array
    {
        return [
            self::NO => '否',
            self::YES => '是',
        ];
    }
}
