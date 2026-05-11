<?php

namespace app\queue\job;

use app\queue\support\AbstractQueueJob;
use app\service\payment\transfer\TransferService;

/**
 * 转账通道查单任务。
 *
 * 负责对仍处于处理中的转账单做延迟查单，并按退避间隔继续投递下一次查单。
 */
class TransferQueryJob extends AbstractQueueJob
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
     * 处理转账查单消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void
    {
        $bizNo = $this->requireString($data, 'biz_no');
        $attempt = max(0, (int) ($data['attempt'] ?? 0));

        $this->transferService->queryQueuedTransfer($bizNo, $attempt);
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return 'TransferQueryQueue';
    }
}
