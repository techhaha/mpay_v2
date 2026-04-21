<?php

declare(strict_types=1);

namespace app\common\interface;

use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 支付动作能力接口。
 *
 * 所有支付插件必须实现此接口，用于统一下单、订单查询、关闭、退款和回调通知。
 * 建议先继承 `BasePayment` 获取 HTTP 请求等通用能力，再实现本接口。
 *
 * 异常约定：实现类在业务失败时应抛出 `PaymentException`，便于统一处理和返回。
 */
interface PaymentInterface
{
    // ==================== 订单操作 ====================

    /**
     * 发起支付下单。
     *
     * @param array $order 订单参数
     * @return array 下单结果
     */
    public function pay(array $order): array;

    /**
     * 查询订单状态。
     *
     * @param array $order 订单参数
     * @return array 查询结果
     */
    public function query(array $order): array;

    /**
     * 关闭订单。
     *
     * @param array $order 订单参数
     * @return array 关闭结果
     */
    public function close(array $order): array;

    /**
     * 申请退款。
     *
     * @param array $order 订单参数
     * @return array 退款结果
     */
    public function refund(array $order): array;

    // ==================== 异步通知 ====================

    /**
     * 解析并验证支付回调通知。
     *
     * @param Request $request 请求对象
     * @return array 回调结果
     */
    public function notify(Request $request): array;

    /**
     * 回调处理成功时返回给第三方的平台响应。
     *
     * @return string|Response 响应内容
     */
    public function notifySuccess(): string|Response;

    /**
     * 回调处理失败时返回给第三方的平台响应。
     *
     * @return string|Response 响应内容
     */
    public function notifyFail(): string|Response;
}




