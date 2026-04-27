<?php

namespace app\repository\payment\notify;

use app\common\base\BaseRepository;
use app\model\payment\NotifyTask;

/**
 * 商户通知任务仓库。
 *
 * 封装通知单号查询和可重试任务列表。
 */
class NotifyTaskRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new NotifyTask());
    }

    /**
     * 根据通知单号查询通知任务。
     *
     * @param string $notifyNo 通知号
     * @param array $columns 字段列表
     * @return NotifyTask|null 通知任务记录
     */
    public function findByNotifyNo(string $notifyNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('notify_no', $notifyNo)
            ->first($columns);
    }

    /**
     * 根据通知事件和引用单号查询通知任务。
     *
     * @param string $eventType 通知事件类型
     * @param string $refNo 事件引用单号
     * @param array $columns 字段列表
     * @return NotifyTask|null 通知任务记录
     */
    public function findByEventRef(string $eventType, string $refNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('event_type', $eventType)
            ->where('ref_no', $refNo)
            ->first($columns);
    }

    /**
     * 查询指定支付单的通知任务列表。
     *
     * @param string $payNo 支付单号
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, NotifyTask> 通知任务列表
     */
    public function listByPayNo(string $payNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->orderByDesc('id')
            ->get($columns);
    }

    /**
     * 查询可重试的通知任务列表。
     *
     * @param int $status 状态
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, NotifyTask> 可重试任务列表
     */
    public function listRetryable(int $status, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', $status)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('next_retry_at')
            ->get($columns);
    }
}



