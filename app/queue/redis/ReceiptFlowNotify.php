<?php

namespace app\queue\redis;

use app\common\constant\PaymentQueueConstant;
use app\queue\job\ReceiptFlowNotifyJob;
use app\queue\support\AbstractRedisConsumer;

/**
 * 网页流水监听通知队列消费者。
 */
class ReceiptFlowNotify extends AbstractRedisConsumer
{
    /**
     * 队列名称。
     *
     * @var string
     */
    public $queue = PaymentQueueConstant::RECEIPT_FLOW_NOTIFY;

    /**
     * 获取任务类名。
     *
     * @return class-string<ReceiptFlowNotifyJob> 任务类名
     */
    protected function jobClass(): string
    {
        return ReceiptFlowNotifyJob::class;
    }
}
