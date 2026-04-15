<?php

namespace app\repository\payment\notify;

use app\common\base\BaseRepository;
use app\model\payment\NotifyTask;

/**
 * 商户通知任务仓库。
 */
class NotifyTaskRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new NotifyTask());
    }

    /**
     * 根据通知单号查询通知任务。
     */
    public function findByNotifyNo(string $notifyNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('notify_no', $notifyNo)
            ->first($columns);
    }

    /**
     * 查询可重试的通知任务列表。
     */
    public function listRetryable(int $status, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', $status)
            ->orderBy('next_retry_at')
            ->get($columns);
    }
}


