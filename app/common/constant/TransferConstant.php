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
     * 转账成功状态。
     */
    public const TRANSFER_STATUS_SUCCESS = 1;
    /**
     * 转账失败状态。
     */
    public const TRANSFER_STATUS_FAILED = 2;

    /**
     * 获取转账状态映射。
     *
     * @return array<int, string>
     */
    public static function transferStatusMap(): array
    {
        return [
            self::TRANSFER_STATUS_PENDING => '待处理',
            self::TRANSFER_STATUS_SUCCESS => '成功',
            self::TRANSFER_STATUS_FAILED => '失败',
        ];
    }
}
