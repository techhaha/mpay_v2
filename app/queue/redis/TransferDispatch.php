<?php

namespace app\queue\redis;

use app\common\constant\PaymentQueueConstant;
use app\queue\job\TransferDispatchJob;
use app\queue\support\AbstractRedisConsumer;

/**
 * 转账通道派发队列消费者。
 *
 * 只声明队列名和业务 Job，具体消费逻辑统一放在 TransferDispatchJob 中。
 */
class TransferDispatch extends AbstractRedisConsumer
{
    /**
     * 队列名称。
     *
     * @var string
     */
    public $queue = PaymentQueueConstant::TRANSFER_DISPATCH;

    /**
     * 获取任务类名。
     *
     * @return class-string<TransferDispatchJob> 任务类名
     */
    protected function jobClass(): string
    {
        return TransferDispatchJob::class;
    }
}
