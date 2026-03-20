<?php

declare(strict_types=1);

namespace app\common\contracts;

use app\exceptions\PaymentException;
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
     *         - order_id: 系统订单号
     *         - mch_no: 商户号
     *         - amount: 金额（元）
     *         - subject: 商品标题
     *         - body: 商品描述
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
     *         - order_id: 原订单号
     *         - chan_order_no: 渠道订单号
     *         - refund_amount: 退款金额
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
     *         - status: 支付状态
     *         - pay_order_id: 系统订单号
     *         - chan_trade_no: 渠道交易号
     *         - amount: 支付金额
     * @throws PaymentException 验签失败、数据异常等
     */
    public function notify(Request $request): array;

    public function notifySuccess(): string|Response;

    public function notifyFail(): string|Response;
}
