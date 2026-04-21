<?php

namespace app\common\constant;

/**
 * 商户相关枚举。
 */
final class MerchantConstant
{
    /**
     * 个人商户类型。
     */
    public const TYPE_PERSON = 0;

    /**
     * 企业商户类型。
     */
    public const TYPE_COMPANY = 1;

    /**
     * 其他商户类型。
     */
    public const TYPE_OTHER = 2;

    /**
     * 低风险等级。
     */
    public const RISK_LOW = 0;

    /**
     * 中风险等级。
     */
    public const RISK_MEDIUM = 1;

    /**
     * 高风险等级。
     */
    public const RISK_HIGH = 2;

    /**
     * 获取商户类型映射。
     *
     * @return array<int, string> 商户类型名称表
     */
    public static function typeMap(): array
    {
        return [
            self::TYPE_PERSON => '个人',
            self::TYPE_COMPANY => '企业',
            self::TYPE_OTHER => '其他',
        ];
    }

    /**
     * 获取商户风险等级映射。
     *
     * @return array<int, string> 风险等级名称表
     */
    public static function riskLevelMap(): array
    {
        return [
            self::RISK_LOW => '低',
            self::RISK_MEDIUM => '中',
            self::RISK_HIGH => '高',
        ];
    }
}




