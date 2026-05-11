<?php

namespace app\queue\job;

use app\queue\support\AbstractQueueJob;
use app\service\payment\settlement\SettlementAutomationService;

/**
 * 清算自动入账任务。
 *
 * 只处理已生成清算单的自动入账，是否满足自动入账策略由清算服务判断。
 */
class SettlementCompleteJob extends AbstractQueueJob
{
    /**
     * 构造方法。
     *
     * @param SettlementAutomationService $settlementAutomationService 清算自动化服务
     * @return void
     */
    public function __construct(
        protected SettlementAutomationService $settlementAutomationService
    ) {
    }

    /**
     * 处理清算自动入账消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void
    {
        $settleNo = $this->requireString($data, 'settle_no', '清算单号');

        $this->settlementAutomationService->completeAutoSettlement($settleNo);
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return 'SettlementCompleteQueue';
    }
}
