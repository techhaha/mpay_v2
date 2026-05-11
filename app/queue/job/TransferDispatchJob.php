<?php

namespace app\queue\job;

use app\queue\support\AbstractQueueJob;
use app\service\payment\transfer\TransferService;

/**
 * 转账通道派发任务。
 *
 * 负责把已扣款落库的转账单请求发送到第三方通道。
 */
class TransferDispatchJob extends AbstractQueueJob
{
    /**
     * 构造方法。
     *
     * @param TransferService $transferService 转账服务
     * @return void
     */
    public function __construct(
        protected TransferService $transferService
    ) {
    }

    /**
     * 处理转账派发消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void
    {
        $bizNo = $this->requireString($data, 'biz_no');
        $this->transferService->dispatchQueuedTransfer($bizNo);
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return 'TransferDispatchQueue';
    }
}
