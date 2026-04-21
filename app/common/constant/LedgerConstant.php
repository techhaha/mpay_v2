<?php

namespace app\common\constant;

/**
 * 账户流水枚举。
 */
final class LedgerConstant
{
    public const BIZ_TYPE_PAY_FREEZE = 0;
    public const BIZ_TYPE_PAY_DEDUCT = 1;
    public const BIZ_TYPE_PAY_RELEASE = 2;
    public const BIZ_TYPE_SETTLEMENT_CREDIT = 3;
    public const BIZ_TYPE_REFUND_REVERSE = 4;
    public const BIZ_TYPE_MANUAL_ADJUST = 5;

    public const EVENT_TYPE_CREATE = 0;
    public const EVENT_TYPE_SUCCESS = 1;
    public const EVENT_TYPE_FAILED = 2;
    public const EVENT_TYPE_REVERSE = 3;

    public const DIRECTION_IN = 0;
    public const DIRECTION_OUT = 1;

    /**
     * 获取业务类型映射。
     *
     * @return array<int, string> 业务类型名称表
     */
    public static function bizTypeMap(): array
    {
        return [
            self::BIZ_TYPE_PAY_FREEZE => '支付冻结',
            self::BIZ_TYPE_PAY_DEDUCT => '支付扣费',
            self::BIZ_TYPE_PAY_RELEASE => '支付释放',
            self::BIZ_TYPE_SETTLEMENT_CREDIT => '清算入账',
            self::BIZ_TYPE_REFUND_REVERSE => '退款冲正',
            self::BIZ_TYPE_MANUAL_ADJUST => '人工调整',
        ];
    }

    /**
     * 获取事件类型映射。
     *
     * @return array<int, string> 事件类型名称表
     */
    public static function eventTypeMap(): array
    {
        return [
            self::EVENT_TYPE_CREATE => '创建',
            self::EVENT_TYPE_SUCCESS => '成功',
            self::EVENT_TYPE_FAILED => '失败',
            self::EVENT_TYPE_REVERSE => '冲正',
        ];
    }

    /**
     * 获取流水方向映射。
     *
     * @return array<int, string> 方向名称表
     */
    public static function directionMap(): array
    {
        return [
            self::DIRECTION_IN => '入账',
            self::DIRECTION_OUT => '出账',
        ];
    }
}




