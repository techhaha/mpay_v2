<?php

namespace app\common\constant;

/**
 * 交易、订单与结算状态枚举。
 */
final class TradeConstant
{
    /**
     * D0 清算周期。
     */
    public const SETTLEMENT_CYCLE_D0 = 0;
    /**
     * D1 清算周期。
     */
    public const SETTLEMENT_CYCLE_D1 = 1;
    /**
     * D7 清算周期。
     */
    public const SETTLEMENT_CYCLE_D7 = 2;
    /**
     * T1 清算周期。
     */
    public const SETTLEMENT_CYCLE_T1 = 3;
    /**
     * 其他清算周期。
     */
    public const SETTLEMENT_CYCLE_OTHER = 4;

    /**
     * 订单已创建，等待发起支付。
     */
    public const ORDER_STATUS_CREATED = 0;
    /**
     * 订单支付中。
     */
    public const ORDER_STATUS_PAYING = 1;
    /**
     * 订单支付成功。
     */
    public const ORDER_STATUS_SUCCESS = 2;
    /**
     * 订单支付失败。
     */
    public const ORDER_STATUS_FAILED = 3;
    /**
     * 订单已关闭。
     */
    public const ORDER_STATUS_CLOSED = 4;
    /**
     * 订单已超时。
     */
    public const ORDER_STATUS_TIMEOUT = 5;

    /**
     * 手续费未处理。
     */
    public const FEE_STATUS_NONE = 0;
    /**
     * 手续费已冻结。
     */
    public const FEE_STATUS_FROZEN = 1;
    /**
     * 手续费已扣除。
     */
    public const FEE_STATUS_DEDUCTED = 2;
    /**
     * 手续费已释放。
     */
    public const FEE_STATUS_RELEASED = 3;

    /**
     * 清算状态为空。
     */
    public const SETTLEMENT_STATUS_NONE = 0;
    /**
     * 清算待处理。
     */
    public const SETTLEMENT_STATUS_PENDING = 1;
    /**
     * 清算已完成。
     */
    public const SETTLEMENT_STATUS_SETTLED = 2;
    /**
     * 清算已冲正。
     */
    public const SETTLEMENT_STATUS_REVERSED = 3;

    /**
     * 退款单已创建。
     */
    public const REFUND_STATUS_CREATED = 0;
    /**
     * 退款单处理中。
     */
    public const REFUND_STATUS_PROCESSING = 1;
    /**
     * 退款单成功。
     */
    public const REFUND_STATUS_SUCCESS = 2;
    /**
     * 退款单失败。
     */
    public const REFUND_STATUS_FAILED = 3;
    /**
     * 退款单已关闭。
     */
    public const REFUND_STATUS_CLOSED = 4;

    /**
     * 获取清算周期映射。
     *
     * @return array<int, string> 清算周期名称表
     */
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

    /**
     * 获取订单状态映射。
     *
     * @return array<int, string> 订单状态名称表
     */
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

    /**
     * 获取手续费状态映射。
     *
     * @return array<int, string> 手续费状态名称表
     */
    public static function feeStatusMap(): array
    {
        return [
            self::FEE_STATUS_NONE => '无',
            self::FEE_STATUS_FROZEN => '冻结',
            self::FEE_STATUS_DEDUCTED => '已扣',
            self::FEE_STATUS_RELEASED => '已释放',
        ];
    }

    /**
     * 获取清算状态映射。
     *
     * @return array<int, string> 清算状态名称表
     */
    public static function settlementStatusMap(): array
    {
        return [
            self::SETTLEMENT_STATUS_NONE => '无',
            self::SETTLEMENT_STATUS_PENDING => '待清算',
            self::SETTLEMENT_STATUS_SETTLED => '已清算',
            self::SETTLEMENT_STATUS_REVERSED => '已冲正',
        ];
    }

    /**
     * 获取退款状态映射。
     *
     * @return array<int, string> 退款状态名称表
     */
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

    /**
     * 获取可变更的订单状态列表。
     *
     * @return array<int, int> 状态列表
     */
    public static function orderMutableStatuses(): array
    {
        return [
            self::ORDER_STATUS_CREATED,
            self::ORDER_STATUS_PAYING,
        ];
    }

    /**
     * 获取订单终态列表。
     *
     * @return array<int, int> 状态列表
     */
    public static function orderTerminalStatuses(): array
    {
        return [
            self::ORDER_STATUS_SUCCESS,
            self::ORDER_STATUS_FAILED,
            self::ORDER_STATUS_CLOSED,
            self::ORDER_STATUS_TIMEOUT,
        ];
    }

    /**
     * 判断订单是否为终态。
     *
     * @param int $status 状态
     * @return bool 是否为终态
     */
    public static function isOrderTerminalStatus(int $status): bool
    {
        return in_array($status, self::orderTerminalStatuses(), true);
    }

    /**
     * 获取可变更的退款状态列表。
     *
     * @return array<int, int> 状态列表
     */
    public static function refundMutableStatuses(): array
    {
        return [
            self::REFUND_STATUS_CREATED,
            self::REFUND_STATUS_PROCESSING,
            self::REFUND_STATUS_FAILED,
        ];
    }

    /**
     * 获取退款终态列表。
     *
     * @return array<int, int> 状态列表
     */
    public static function refundTerminalStatuses(): array
    {
        return [
            self::REFUND_STATUS_SUCCESS,
            self::REFUND_STATUS_CLOSED,
        ];
    }

    /**
     * 判断退款是否为终态。
     *
     * @param int $status 状态
     * @return bool 是否为终态
     */
    public static function isRefundTerminalStatus(int $status): bool
    {
        return in_array($status, self::refundTerminalStatuses(), true);
    }

    /**
     * 获取可变更的清算状态列表。
     *
     * @return array<int, int> 状态列表
     */
    public static function settlementMutableStatuses(): array
    {
        return [
            self::SETTLEMENT_STATUS_PENDING,
        ];
    }

    /**
     * 获取清算终态列表。
     *
     * @return array<int, int> 状态列表
     */
    public static function settlementTerminalStatuses(): array
    {
        return [
            self::SETTLEMENT_STATUS_SETTLED,
            self::SETTLEMENT_STATUS_REVERSED,
        ];
    }

    /**
     * 判断清算是否为终态。
     *
     * @param int $status 状态
     * @return bool 是否为终态
     */
    public static function isSettlementTerminalStatus(int $status): bool
    {
        return in_array($status, self::settlementTerminalStatuses(), true);
    }
}



