<?php

namespace app\queue\redis;

use app\common\constant\PaymentQueueConstant;
use app\queue\job\SettlementCompleteJob;
use app\queue\support\AbstractRedisConsumer;

/**
 * 清算自动入账队列消费者。
 */
class SettlementComplete extends AbstractRedisConsumer
{
    /**
     * 队列名称。
     *
     * @var string
     */
    public $queue = PaymentQueueConstant::SETTLEMENT_COMPLETE;

    /**
     * 获取任务类名。
     *
     * @return class-string<SettlementCompleteJob> 任务类名
     */
    protected function jobClass(): string
    {
        return SettlementCompleteJob::class;
    }
}
