<?php

namespace app\queue\redis;

use app\common\constant\PaymentQueueConstant;
use app\queue\job\RefundDispatchJob;
use app\queue\support\AbstractRedisConsumer;

/**
 * 退款通道请求队列消费者。
 *
 * 只声明队列名和业务 Job，具体消费逻辑统一放在 RefundDispatchJob 中。
 */
class RefundDispatch extends AbstractRedisConsumer
{
    /**
     * 队列名称。
     *
     * @var string
     */
    public $queue = PaymentQueueConstant::REFUND_DISPATCH;

    /**
     * 获取任务类名。
     *
     * @return class-string<RefundDispatchJob> 任务类名
     */
    protected function jobClass(): string
    {
        return RefundDispatchJob::class;
    }
}
