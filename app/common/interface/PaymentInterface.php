<?php

declare(strict_types=1);

namespace app\common\interface;

use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 支付插件接口
 *
 * 所有支付插件必须实现此接口，用于统一下单、订单查询、关闭、退款及回调通知等核心能力。
 * 建议继承 BasePayment 获得 HTTP 请求等通用能力，再实现本接口。
 *
 * 异常约定：实现类在业务失败时应抛出 PaymentException，便于统一处理和返回。
 */
interface PaymentInterface
{
    // ==================== 订单操作 ====================

    /**
     * 统一下单
     *
     * @param array<string, mixed> $order 订单数据，通常包含：
     *         - order_id: 系统支付单号，建议直接使用 pay_no
     *         - amount: 金额（分）
     *         - subject: 商品标题
     *         - body: 商品描述
     *         - callback_url: 第三方异步回调地址（回调到本系统）
     *         - return_url: 支付完成跳转地址
     * @return array<string, mixed> 支付参数，需包含 pay_params、chan_order_no、chan_trade_no
     * @throws PaymentException 下单失败、渠道异常、参数错误等
     */
    public function pay(array $order): array;

    /**
     * 查询订单状态
     *
     * @param array<string, mixed> $order 订单数据（至少含 order_id、chan_order_no）
     * @return array<string, mixed> 订单状态信息，通常包含：
     *         - status: 订单状态
     *         - chan_trade_no: 渠道交易号
     *         - pay_amount: 实付金额
     * @throws PaymentException 查询失败、渠道异常等
     */
    public function query(array $order): array;

    /**
     * 关闭订单
     *
     * @param array<string, mixed> $order 订单数据（至少含 order_id、chan_order_no）
     * @return array<string, mixed> 关闭结果，通常包含 success、msg
     * @throws PaymentException 关闭失败、渠道异常等
     */
    public function close(array $order): array;

    /**
     * 申请退款
     *
     * @param array<string, mixed> $order 退款数据，通常包含：
     *         - order_id: 原支付单号
     *         - chan_order_no: 渠道订单号
     *         - refund_amount: 退款金额（分）
     *         - refund_no: 退款单号
     * @return array<string, mixed> 退款结果，通常包含 success、chan_refund_no、msg
     * @throws PaymentException 退款失败、渠道异常等
     */
    public function refund(array $order): array;

    // ==================== 异步通知 ====================

    /**
     * 解析并验证支付回调通知
     *
     * @param Request $request 支付渠道的异步通知请求（GET/POST 参数）
     * @return array<string, mixed> 解析结果，通常包含：
     *         - success: 是否支付成功
     *         - status: 插件解析出的渠道状态文本
     *         - pay_order_id: 系统支付单号
     *         - chan_trade_no: 渠道交易号
     *         - chan_order_no: 渠道订单号
     *         - amount: 支付金额（分）
     *         - paid_at: 支付成功时间
     * @throws PaymentException 验签失败、数据异常等
     */
    public function notify(Request $request): array;

    /**
     * 回调处理成功时返回给第三方的平台响应。
     */
    public function notifySuccess(): string|Response;

    /**
     * 回调处理失败时返回给第三方的平台响应。
     */
    public function notifyFail(): string|Response;
}
