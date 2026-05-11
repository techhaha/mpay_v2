<?php

namespace app\queue\redis;

use app\common\constant\PaymentQueueConstant;
use app\queue\job\TransferQueryJob;
use app\queue\support\AbstractRedisConsumer;

/**
 * 转账通道查单队列消费者。
 *
 * 只声明队列名和业务 Job，具体消费逻辑统一放在 TransferQueryJob 中。
 */
class TransferQuery extends AbstractRedisConsumer
{
    /**
     * 队列名称。
     *
     * @var string
     */
    public $queue = PaymentQueueConstant::TRANSFER_QUERY;

    /**
     * 获取任务类名。
     *
     * @return class-string<TransferQueryJob> 任务类名
     */
    protected function jobClass(): string
    {
        return TransferQueryJob::class;
    }
}
