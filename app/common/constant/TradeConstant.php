<?php

namespace app\common\constant;

/**
 * 交易、订单与结算相关枚举。
 */
final class TradeConstant
{
    public const SETTLEMENT_CYCLE_D0 = 0;
    public const SETTLEMENT_CYCLE_D1 = 1;
    public const SETTLEMENT_CYCLE_D7 = 2;
    public const SETTLEMENT_CYCLE_T1 = 3;
    public const SETTLEMENT_CYCLE_OTHER = 4;

    public const ORDER_STATUS_CREATED = 0;
    public const ORDER_STATUS_PAYING = 1;
    public const ORDER_STATUS_SUCCESS = 2;
    public const ORDER_STATUS_FAILED = 3;
    public const ORDER_STATUS_CLOSED = 4;
    public const ORDER_STATUS_TIMEOUT = 5;

    public const FEE_STATUS_NONE = 0;
    public const FEE_STATUS_FROZEN = 1;
    public const FEE_STATUS_DEDUCTED = 2;
    public const FEE_STATUS_RELEASED = 3;

    public const SETTLEMENT_STATUS_NONE = 0;
    public const SETTLEMENT_STATUS_PENDING = 1;
    public const SETTLEMENT_STATUS_SETTLED = 2;
    public const SETTLEMENT_STATUS_REVERSED = 3;

    public const REFUND_STATUS_CREATED = 0;
    public const REFUND_STATUS_PROCESSING = 1;
    public const REFUND_STATUS_SUCCESS = 2;
    public const REFUND_STATUS_FAILED = 3;
    public const REFUND_STATUS_CLOSED = 4;

    public static function settlementCycleMap(): array
    {
        return [
            self::SETTLEMENT_CYCLE_D0 => 'D0',
            self::SETTLEMENT_CYCLE_D1 => 'D1',
            self::SETTLEMENT_CYCLE_D7 => 'D7',
            self::SETTLEMENT_CYCLE_T1 => 'T1',
            self::SETTLEMENT_CYCLE_OTHER => 'OTHER',
        ];
    }

    public static function orderStatusMap(): array
    {
        return [
            self::ORDER_STATUS_CREATED => '待创建',
            self::ORDER_STATUS_PAYING => '支付中',
            self::ORDER_STATUS_SUCCESS => '成功',
            self::ORDER_STATUS_FAILED => '失败',
            self::ORDER_STATUS_CLOSED => '关闭',
            self::ORDER_STATUS_TIMEOUT => '超时',
        ];
    }

    public static function feeStatusMap(): array
    {
        return [
            self::FEE_STATUS_NONE => '无',
            self::FEE_STATUS_FROZEN => '冻结',
            self::FEE_STATUS_DEDUCTED => '已扣',
            self::FEE_STATUS_RELEASED => '已释放',
        ];
    }

    public static function settlementStatusMap(): array
    {
        return [
            self::SETTLEMENT_STATUS_NONE => '无',
            self::SETTLEMENT_STATUS_PENDING => '待清算',
            self::SETTLEMENT_STATUS_SETTLED => '已清算',
            self::SETTLEMENT_STATUS_REVERSED => '已冲正',
        ];
    }

    public static function refundStatusMap(): array
    {
        return [
            self::REFUND_STATUS_CREATED => '待创建',
            self::REFUND_STATUS_PROCESSING => '处理中',
            self::REFUND_STATUS_SUCCESS => '成功',
            self::REFUND_STATUS_FAILED => '失败',
            self::REFUND_STATUS_CLOSED => '关闭',
        ];
    }

    public static function orderMutableStatuses(): array
    {
        return [
            self::ORDER_STATUS_CREATED,
            self::ORDER_STATUS_PAYING,
        ];
    }

    public static function orderTerminalStatuses(): array
    {
        return [
            self::ORDER_STATUS_SUCCESS,
            self::ORDER_STATUS_FAILED,
            self::ORDER_STATUS_CLOSED,
            self::ORDER_STATUS_TIMEOUT,
        ];
    }

    public static function isOrderTerminalStatus(int $status): bool
    {
        return in_array($status, self::orderTerminalStatuses(), true);
    }

    public static function refundMutableStatuses(): array
    {
        return [
            self::REFUND_STATUS_CREATED,
            self::REFUND_STATUS_PROCESSING,
            self::REFUND_STATUS_FAILED,
        ];
    }

    public static function refundTerminalStatuses(): array
    {
        return [
            self::REFUND_STATUS_SUCCESS,
            self::REFUND_STATUS_CLOSED,
        ];
    }

    public static function isRefundTerminalStatus(int $status): bool
    {
        return in_array($status, self::refundTerminalStatuses(), true);
    }

    public static function settlementMutableStatuses(): array
    {
        return [
            self::SETTLEMENT_STATUS_PENDING,
        ];
    }

    public static function settlementTerminalStatuses(): array
    {
        return [
            self::SETTLEMENT_STATUS_SETTLED,
            self::SETTLEMENT_STATUS_REVERSED,
        ];
    }

    public static function isSettlementTerminalStatus(int $status): bool
    {
        return in_array($status, self::settlementTerminalStatuses(), true);
    }
}
