<?php

namespace app\common\constant;

/**
 * 支付队列名称常量。
 *
 * 队列名是生产者与消费者之间的协议，不建议写在业务服务或消费者里。
 * 新增支付域队列时优先在本类登记，再分别实现投递服务与消费者。
 */
final class PaymentQueueConstant
{
    /**
     * 商户异步通知队列名。
     */
    public const MERCHANT_NOTIFY = 'merchant_notify';

    /**
     * 退款上游派发队列名。
     */
    public const REFUND_DISPATCH = 'refund_dispatch';

    /**
     * 转账上游派发队列名。
     */
    public const TRANSFER_DISPATCH = 'transfer_dispatch';

    /**
     * 转账上游查单队列名。
     */
    public const TRANSFER_QUERY = 'transfer_query';

    /**
     * 清算自动入账队列名。
     */
    public const SETTLEMENT_COMPLETE = 'settlement_complete';

    /**
     * 网页流水监听通知队列名。
     */
    public const RECEIPT_FLOW_NOTIFY = 'receipt_flow_notify';
}
