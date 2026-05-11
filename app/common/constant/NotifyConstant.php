<?php

namespace app\common\constant;

/**
 * 通知与回调枚举。
 */
final class NotifyConstant
{
    /**
     * 商户通知事件：支付成功。
     */
    public const EVENT_PAY_SUCCESS = 'PAY_SUCCESS';
    /**
     * 商户通知事件：退款成功。
     */
    public const EVENT_REFUND_SUCCESS = 'REFUND_SUCCESS';
    /**
     * 商户通知事件：清算完成。
     */
    public const EVENT_SETTLEMENT_SUCCESS = 'SETTLEMENT_SUCCESS';

    /**
     * 商户通知成功响应。
     */
    public const MERCHANT_SUCCESS_RESPONSE = 'success';

    /**
     * ePay 通知交易成功状态。
     */
    public const EPAY_TRADE_STATUS_SUCCESS = 'TRADE_SUCCESS';

    /**
     * 异步通知类型。
     */
    public const NOTIFY_TYPE_ASYNC = 0;
    /**
     * 查单通知类型。
     */
    public const NOTIFY_TYPE_QUERY = 1;

    /**
     * 异步回调类型。
     */
    public const CALLBACK_TYPE_ASYNC = 0;
    /**
     * 同步回调类型。
     */
    public const CALLBACK_TYPE_SYNC = 1;

    /**
     * 验证状态：未知。
     */
    public const VERIFY_STATUS_UNKNOWN = 0;
    /**
     * 验证状态：成功。
     */
    public const VERIFY_STATUS_SUCCESS = 1;
    /**
     * 验证状态：失败。
     */
    public const VERIFY_STATUS_FAILED = 2;

    /**
     * 处理状态：待处理。
     */
    public const PROCESS_STATUS_PENDING = 0;
    /**
     * 处理状态：成功。
     */
    public const PROCESS_STATUS_SUCCESS = 1;
    /**
     * 处理状态：失败。
     */
    public const PROCESS_STATUS_FAILED = 2;

    /**
     * 任务状态：待通知。
     */
    public const TASK_STATUS_PENDING = 0;
    /**
     * 任务状态：成功。
     */
    public const TASK_STATUS_SUCCESS = 1;
    /**
     * 任务状态：失败。
     */
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

    /**
     * 获取商户通知事件映射。
     *
     * @return array<string, string> 商户通知事件名称表
     */
    public static function eventTypeMap(): array
    {
        return [
            self::EVENT_PAY_SUCCESS => '支付成功',
            self::EVENT_REFUND_SUCCESS => '退款成功',
            self::EVENT_SETTLEMENT_SUCCESS => '清算完成',
        ];
    }
}


