<?php

namespace app\common\constant;

/**
 * 账户流水枚举。
 */
final class LedgerConstant
{
    /**
     * 支付冻结流水。
     */
    public const BIZ_TYPE_PAY_FREEZE = 0;
    /**
     * 支付扣费流水。
     */
    public const BIZ_TYPE_PAY_DEDUCT = 1;
    /**
     * 支付释放流水。
     */
    public const BIZ_TYPE_PAY_RELEASE = 2;
    /**
     * 清算入账流水。
     */
    public const BIZ_TYPE_SETTLEMENT_CREDIT = 3;
    /**
     * 退款冲正流水。
     */
    public const BIZ_TYPE_REFUND_REVERSE = 4;
    /**
     * 人工调整流水。
     */
    public const BIZ_TYPE_MANUAL_ADJUST = 5;
    /**
     * 转账扣款流水。
     */
    public const BIZ_TYPE_TRANSFER_DEDUCT = 6;
    /**
     * 转账手续费流水。
     */
    public const BIZ_TYPE_TRANSFER_FEE = 7;
    /**
     * 转账释放流水。
     */
    public const BIZ_TYPE_TRANSFER_RELEASE = 8;
    /**
     * 风控资金冻结流水。
     */
    public const BIZ_TYPE_RISK_FREEZE = 9;
    /**
     * 风控资金释放流水。
     */
    public const BIZ_TYPE_RISK_RELEASE = 10;

    /**
     * 账务事件的创建动作。
     */
    public const EVENT_TYPE_CREATE = 0;
    /**
     * 账务事件的成功动作。
     */
    public const EVENT_TYPE_SUCCESS = 1;
    /**
     * 账务事件的失败动作。
     */
    public const EVENT_TYPE_FAILED = 2;
    /**
     * 账务事件的冲正动作。
     */
    public const EVENT_TYPE_REVERSE = 3;

    /**
     * 流水入账方向。
     */
    public const DIRECTION_IN = 0;
    /**
     * 流水出账方向。
     */
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
            self::BIZ_TYPE_TRANSFER_DEDUCT => '转账扣款',
            self::BIZ_TYPE_TRANSFER_FEE => '转账手续费',
            self::BIZ_TYPE_TRANSFER_RELEASE => '转账释放',
            self::BIZ_TYPE_RISK_FREEZE => '风控冻结',
            self::BIZ_TYPE_RISK_RELEASE => '风控释放',
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

