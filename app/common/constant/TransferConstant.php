<?php

namespace app\common\constant;

/**
 * 转账状态枚举。
 */
final class TransferConstant
{
    /**
     * 转账待处理状态。
     */
    public const TRANSFER_STATUS_PENDING = 0;
    /**
     * 转账处理中状态。
     */
    public const TRANSFER_STATUS_PROCESSING = 1;
    /**
     * 转账成功状态。
     */
    public const TRANSFER_STATUS_SUCCESS = 2;
    /**
     * 转账失败状态。
     */
    public const TRANSFER_STATUS_FAILED = 3;
    /**
     * 转账关闭状态。
     */
    public const TRANSFER_STATUS_CLOSED = 4;

    /**
     * 获取转账状态映射。
     *
     * @return array<int, string>
     */
    public static function transferStatusMap(): array
    {
        return [
            self::TRANSFER_STATUS_PENDING => '待处理',
            self::TRANSFER_STATUS_PROCESSING => '处理中',
            self::TRANSFER_STATUS_SUCCESS => '成功',
            self::TRANSFER_STATUS_FAILED => '失败',
            self::TRANSFER_STATUS_CLOSED => '关闭',
        ];
    }

    /**
     * 判断是否为转账终态。
     *
     * @param int $status 转账状态
     * @return bool 是否终态
     */
    public static function isTerminalStatus(int $status): bool
    {
        return in_array($status, [
            self::TRANSFER_STATUS_SUCCESS,
            self::TRANSFER_STATUS_FAILED,
            self::TRANSFER_STATUS_CLOSED,
        ], true);
    }
}
