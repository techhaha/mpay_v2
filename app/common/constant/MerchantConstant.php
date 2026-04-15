<?php

namespace app\common\constant;

/**
 * 商户相关枚举。
 */
final class MerchantConstant
{
    public const TYPE_PERSON = 0;
    public const TYPE_COMPANY = 1;
    public const TYPE_OTHER = 2;

    public const RISK_LOW = 0;
    public const RISK_MEDIUM = 1;
    public const RISK_HIGH = 2;

    public static function typeMap(): array
    {
        return [
            self::TYPE_PERSON => '个人',
            self::TYPE_COMPANY => '企业',
            self::TYPE_OTHER => '其他',
        ];
    }

    public static function riskLevelMap(): array
    {
        return [
            self::RISK_LOW => '低',
            self::RISK_MEDIUM => '中',
            self::RISK_HIGH => '高',
        ];
    }
}
