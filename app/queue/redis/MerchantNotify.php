<?php

namespace app\queue\redis;

use app\common\constant\PaymentQueueConstant;
use app\queue\job\MerchantNotifyJob;
use app\queue\support\AbstractRedisConsumer;

/**
 * 商户通知队列消费者。
 *
 * 只声明队列名和业务 Job，具体消费逻辑统一放在 MerchantNotifyJob 中。
 */
class MerchantNotify extends AbstractRedisConsumer
{
    /**
     * 队列名称。
     *
     * @var string
     */
    public $queue = PaymentQueueConstant::MERCHANT_NOTIFY;

    /**
     * 获取任务类名。
     *
     * @return class-string<MerchantNotifyJob> 任务类名
     */
    protected function jobClass(): string
    {
        return MerchantNotifyJob::class;
    }
}
