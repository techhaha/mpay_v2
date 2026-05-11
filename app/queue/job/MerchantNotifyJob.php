<?php

namespace app\queue\job;

use app\queue\support\AbstractQueueJob;
use app\service\payment\runtime\MerchantNotifyDispatcherService;

/**
 * 商户通知任务。
 *
 * 负责根据通知号派发一次商户 notify_url；业务失败不抛给 Redis 队列快速重试，
 * 后续重试节奏由通知任务表控制。
 */
class MerchantNotifyJob extends AbstractQueueJob
{
    /**
     * 构造方法。
     *
     * @param MerchantNotifyDispatcherService $dispatcher 商户通知派发服务
     * @return void
     */
    public function __construct(
        protected MerchantNotifyDispatcherService $dispatcher
    ) {
    }

    /**
     * 处理商户通知消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void
    {
        $notifyNo = $this->requireString($data, 'notify_no');
        $this->dispatcher->dispatchTask($notifyNo, false);
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return 'MerchantNotifyQueue';
    }
}
