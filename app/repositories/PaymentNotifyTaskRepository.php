<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\PaymentNotifyTask;

/**
 * 商户通知任务仓储
 */
class PaymentNotifyTaskRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentNotifyTask());
    }

    public function findByOrderId(string $orderId): ?PaymentNotifyTask
    {
        return $this->model->newQuery()
            ->where('order_id', $orderId)
            ->first();
    }

    public function getPendingRetryTasks(int $limit = 100): array
    {
        return $this->model->newQuery()
            ->where('status', PaymentNotifyTask::STATUS_PENDING)
            ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
