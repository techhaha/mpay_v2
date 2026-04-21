<?php

namespace app\common\constant;

/**
 * 通知与回调枚举。
 */
final class NotifyConstant
{
    public const NOTIFY_TYPE_ASYNC = 0;
    public const NOTIFY_TYPE_QUERY = 1;

    public const CALLBACK_TYPE_ASYNC = 0;
    public const CALLBACK_TYPE_SYNC = 1;

    public const VERIFY_STATUS_UNKNOWN = 0;
    public const VERIFY_STATUS_SUCCESS = 1;
    public const VERIFY_STATUS_FAILED = 2;

    public const PROCESS_STATUS_PENDING = 0;
    public const PROCESS_STATUS_SUCCESS = 1;
    public const PROCESS_STATUS_FAILED = 2;

    public const TASK_STATUS_PENDING = 0;
    public const TASK_STATUS_SUCCESS = 1;
    public const TASK_STATUS_FAILED = 2;

    /**
     * 获取通知类型映射。
     *
     * @return array<int, string> 通知类型名称表
     */
    public static function notifyTypeMap(): array
    {
        return [
            self::NOTIFY_TYPE_ASYNC => '异步通知',
            self::NOTIFY_TYPE_QUERY => '查单',
        ];
    }

    /**
     * 获取回调类型映射。
     *
     * @return array<int, string> 回调类型名称表
     */
    public static function callbackTypeMap(): array
    {
        return [
            self::CALLBACK_TYPE_ASYNC => '异步通知',
            self::CALLBACK_TYPE_SYNC => '同步返回',
        ];
    }

    /**
     * 验证状态Map
     *
     * @return array<int, string> 验证状态名称表
     */
    public static function verifyStatusMap(): array
    {
        return [
            self::VERIFY_STATUS_UNKNOWN => '未知',
            self::VERIFY_STATUS_SUCCESS => '成功',
            self::VERIFY_STATUS_FAILED => '失败',
        ];
    }

    /**
     * 处理状态Map
     *
     * @return array<int, string> 处理状态名称表
     */
    public static function processStatusMap(): array
    {
        return [
            self::PROCESS_STATUS_PENDING => '待处理',
            self::PROCESS_STATUS_SUCCESS => '成功',
            self::PROCESS_STATUS_FAILED => '失败',
        ];
    }

    /**
     * 获取任务状态映射。
     *
     * @return array<int, string> 任务状态名称表
     */
    public static function taskStatusMap(): array
    {
        return [
            self::TASK_STATUS_PENDING => '待通知',
            self::TASK_STATUS_SUCCESS => '成功',
            self::TASK_STATUS_FAILED => '失败',
        ];
    }
}




