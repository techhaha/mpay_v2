<?php

namespace app\jobs;

use app\models\PaymentNotifyTask;
use app\repositories\PaymentNotifyTaskRepository;
use app\services\NotifyService;
use support\Log;

/**
 * 商户通知任务
 *
 * 异步发送支付结果通知给商户
 */
class NotifyMerchantJob
{
    public function __construct(
        protected PaymentNotifyTaskRepository $notifyTaskRepository,
        protected NotifyService $notifyService
    ) {
    }

    public function handle(): void
    {
        $tasks = $this->notifyTaskRepository->getPendingRetryTasks(100);

        foreach ($tasks as $taskData) {
            try {
                $task = $this->notifyTaskRepository->find($taskData['id']);
                if (!$task) {
                    continue;
                }

                if ($task->retry_cnt >= 10) {
                    $this->notifyTaskRepository->updateById($task->id, [
                        'status' => PaymentNotifyTask::STATUS_FAIL,
                    ]);
                    continue;
                }

                $this->notifyService->sendNotify($task);
            } catch (\Throwable $e) {
                Log::error('通知任务处理失败', [
                    'task_id' => $taskData['id'] ?? 0,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

