<?php

namespace app\common\constant;

/**
 * 支付插件协议状态常量。
 *
 * 这些状态用于插件和平台运行时之间传递渠道结果，不等同于内部订单状态码。
 */
final class PaymentPluginStatusConstant
{
    /**
     * 渠道支付成功。
     */
    public const SUCCESS = 'success';

    /**
     * 渠道支付失败。
     */
    public const FAILED = 'failed';

    /**
     * 渠道仍在处理中。
     */
    public const PENDING = 'pending';

    /**
     * 渠道订单已关闭。
     */
    public const CLOSED = 'closed';

    /**
     * 渠道状态未知。
     */
    public const UNKNOWN = 'unknown';

    /**
     * 插件回调允许返回的状态。
     *
     * @return array<int, string>
     */
    public static function notifyStatuses(): array
    {
        return [
            self::SUCCESS,
            self::FAILED,
            self::PENDING,
        ];
    }

    /**
     * 插件查单成功状态别名。
     *
     * @return array<int, string>
     */
    public static function successQueryAliases(): array
    {
        return [
            self::SUCCESS,
            'paid',
            'pay_success',
            'trade_success',
            'trade_finished',
            'finished',
            'successful',
        ];
    }

    /**
     * 插件查单失败状态别名。
     *
     * @return array<int, string>
     */
    public static function failedQueryAliases(): array
    {
        return [
            self::FAILED,
            'fail',
            'error',
            'pay_error',
            'trade_fail',
        ];
    }

    /**
     * 插件查单关闭状态别名。
     *
     * @return array<int, string>
     */
    public static function closedQueryAliases(): array
    {
        return [
            self::CLOSED,
            'close',
            'trade_closed',
        ];
    }
}
