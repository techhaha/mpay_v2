<?php

namespace app\repository\payment\notify;

use app\common\base\BaseRepository;
use app\common\constant\NotifyConstant;
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
     * 在当前事务内锁定通知任务，串行化同一任务的结果写回。
     *
     * @param string $notifyNo 通知任务号
     * @return NotifyTask|null 任务不存在时返回 null
     */
    public function findForUpdateByNotifyNo(string $notifyNo): ?NotifyTask
    {
        return $this->model->newQuery()
            ->where('notify_no', $notifyNo)
            ->lockForUpdate()
            ->first();
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
     * 限量查询到期且仍有重试额度的通知任务。
     *
     * 包含首次投递丢失的 PENDING 任务；同一时间的任务按 ID 稳定排序。
     *
     * @param int $limit 本轮最多投递的任务数
     * @param int $retryLimit 最大失败次数
     * @return \Illuminate\Database\Eloquent\Collection<int, NotifyTask> 到期任务
     */
    public function listRetryable(int $limit, int $retryLimit)
    {
        return $this->model->newQuery()
            ->whereIn('status', [NotifyConstant::TASK_STATUS_PENDING, NotifyConstant::TASK_STATUS_FAILED])
            ->where('retry_count', '<', $retryLimit)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }
}


