<?php

namespace app\common\constant;

/**
 * 商户资金冻结常量。
 *
 * 冻结记录是提现、退款、通知等高风险动作的统一风控依据。
 */
final class FundFreezeConstant
{
    /**
     * 支付订单冻结。
     */
    public const TYPE_PAY_ORDER = 1;

    /**
     * 人工指定金额冻结。
     */
    public const TYPE_MANUAL_AMOUNT = 2;

    /**
     * 支付平台服务费预冻结。
     */
    public const TYPE_PAY_FEE = 3;

    /**
     * 冻结中。
     */
    public const STATUS_ACTIVE = 1;

    /**
     * 已解冻。
     */
    public const STATUS_RELEASED = 2;

    /**
     * 获取冻结类型文案。
     *
     * @return array<int, string> 冻结类型文案
     */
    public static function typeMap(): array
    {
        return [
            self::TYPE_PAY_ORDER => '支付订单',
            self::TYPE_MANUAL_AMOUNT => '人工指定金额',
            self::TYPE_PAY_FEE => '支付平台服务费',
        ];
    }

    /**
     * 获取冻结状态文案。
     *
     * @return array<int, string> 冻结状态文案
     */
    public static function statusMap(): array
    {
        return [
            self::STATUS_ACTIVE => '冻结中',
            self::STATUS_RELEASED => '已解冻',
        ];
    }
}
