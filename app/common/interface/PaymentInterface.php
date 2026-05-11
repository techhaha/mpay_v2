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
     * 插件必须返回系统标准结构，服务层会严格校验后写入支付单 `ext_json.presentation`：
     * - `pay_page`：收银台承接页类型，支持 `qrcode`、`html`、`jump`、`jsapi`、`urlscheme`、`error`、`ok`、`page`
     * - `pay_type`：支付方式，例如 `alipay`、`wxpay`、`unionpay`
     * - `pay_product`：插件调用的支付产品，例如 `scan`、`h5`、`jsapi`；没有更细产品时可与 `pay_type` 相同
     * - `pay_action`：插件调用动作或方法，例如 `qrcode`、`jump`、`html`、`jsapi`
     * - `pay_params`：插件调用支付产品返回的支付参数，平台不向其中补写展示字段
     * - `chan_order_no`：渠道订单号；`error`、`html`、`ok` 场景允许为空
     * - `chan_trade_no`：渠道交易号，可选；未生成时返回空字符串
     *
     * `pay_params` 按对应 `pay_page` 放入承接页需要的固定字段：
     * - `qrcode`：`qrcode`，二维码文字内容。
     * - `html`：`html`，插件返回的 HTML 代码。
     * - `jump`：`url`，需要跳转的支付地址。
     * - `jsapi`：微信、支付宝官方 JSAPI 拉起参数。
     * - `urlscheme`：`urlscheme`，需要打开的应用或小程序地址。
     * - `error`：`error_msg`，承接页展示的错误信息。
     * - `ok`：无需固定支付参数，承接页会优先使用订单 `return_url` 返回商户。
     * - `page`：`_page`，已在收银台白名单注册的组件名，其他字段由该组件消费。
     *
     * 插件可把上游原始响应放入 `pay_params.raw` 方便排障，这是推荐约定，不由平台强制校验。
     *
     * @param array $order 订单参数
     * @return array 下单结果
     */
    public function pay(array $order): array;

    /**
     * 查询订单状态。
     *
     * 建议返回当前系统标准结构，定时维护进程会按 `status` 推进支付单：
     * - `success=true|false`：查询请求是否成功；查询失败不等于支付失败
     * - `status`：`success` / `failed` / `closed` / `pending`
     * - `channel_order_no` / `channel_trade_no`：渠道单号
     * - `channel_status`：渠道原始状态，可选
     * - `message`：查询说明，可选
     * - `paid_at` / `failed_at`：终态时间，可选
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
     * 插件应返回当前系统统一可消费的结果结构，核心字段如下：
     * - `status`：支付状态，限定为 `success` / `failed` / `pending`
     * - `channel_order_no` / `channel_trade_no`：渠道单号，必须返回
     * - `channel_status`：渠道原始状态码或状态文本，可选
     * - `message`：回调处理说明，可选
     * - `channel_error_code` / `channel_error_msg`：渠道失败原因，可选
     * - `paid_at` / `failed_at`：支付成功或失败时间，可选
     *
     * 插件在验签失败、报文非法或关键字段缺失时，应直接抛出 `PaymentException`。
     * 只有在回调可信时，才返回标准结果数组。
     * 如果第三方渠道只返回了一个唯一订单号，插件应同时填充 `channel_order_no` 和 `channel_trade_no`，
     * 两个字段可以写成相同值。
     * 业务上尚未终态时返回 `status=pending`，由系统统一记录回调日志而不推进支付单终态。
     *
     * @param Request $request 请求对象
     * @return array<string, mixed> 回调结果
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




