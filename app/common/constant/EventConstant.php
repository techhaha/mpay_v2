<?php

namespace app\common\constant;

/**
 * 事件名称常量。
 *
 * 本类维护已定义的领域事件；事件是否实际启用处理器，以 config/event.php 注册为准。
 * 生命周期服务可以先 dispatch 关键节点事件，后续需要告警、风控、统计或补偿时再注册 listener。
 */
final class EventConstant
{
    /**
     * 系统配置已变更。
     */
    public const SYSTEM_CONFIG_CHANGED = 'system.config.changed';

    /**
     * 支付单首次进入成功态。
     */
    public const PAYMENT_PAY_ORDER_SUCCEEDED = 'payment.pay_order.succeeded';

    /**
     * 支付单进入失败态。
     */
    public const PAYMENT_PAY_ORDER_FAILED = 'payment.pay_order.failed';

    /**
     * 支付单进入关闭态。
     */
    public const PAYMENT_PAY_ORDER_CLOSED = 'payment.pay_order.closed';

    /**
     * 支付单进入超时态。
     */
    public const PAYMENT_PAY_ORDER_TIMEOUT = 'payment.pay_order.timeout';

    /**
     * 退款单进入成功态。
     */
    public const REFUND_ORDER_SUCCEEDED = 'payment.refund_order.succeeded';

    /**
     * 退款单进入失败态。
     */
    public const REFUND_ORDER_FAILED = 'payment.refund_order.failed';

    /**
     * 清算单进入成功态。
     */
    public const SETTLEMENT_ORDER_SUCCEEDED = 'payment.settlement_order.succeeded';

    /**
     * 清算单进入失败态。
     */
    public const SETTLEMENT_ORDER_FAILED = 'payment.settlement_order.failed';

    /**
     * 商户通知派发成功。
     */
    public const MERCHANT_NOTIFY_SUCCEEDED = 'payment.merchant_notify.succeeded';

    /**
     * 商户通知派发失败。
     */
    public const MERCHANT_NOTIFY_FAILED = 'payment.merchant_notify.failed';
}
