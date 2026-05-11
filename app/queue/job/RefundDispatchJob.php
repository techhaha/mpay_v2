<?php

namespace app\queue\job;

use app\queue\support\AbstractQueueJob;
use app\service\payment\order\RefundDispatchService;

/**
 * 退款派发任务。
 *
 * 负责把退款单请求发送到第三方通道，派发异常会由退款派发服务落库为失败状态。
 */
class RefundDispatchJob extends AbstractQueueJob
{
    /**
     * 构造方法。
     *
     * @param RefundDispatchService $dispatcher 退款派发服务
     * @return void
     */
    public function __construct(
        protected RefundDispatchService $dispatcher
    ) {
    }

    /**
     * 处理退款派发消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void
    {
        $refundNo = $this->requireString($data, 'refund_no');
        $isRetry = $this->boolValue($data['is_retry'] ?? false);

        $this->dispatcher->dispatch($refundNo, $isRetry, false);
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return 'RefundDispatchQueue';
    }
}
