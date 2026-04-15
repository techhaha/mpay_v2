<?php

namespace app\repository\ops\log;

use app\common\base\BaseRepository;
use app\model\admin\ChannelNotifyLog;

/**
 * 渠道通知日志仓库。
 */
class ChannelNotifyLogRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new ChannelNotifyLog());
    }

    /**
     * 根据通知单号查询渠道通知日志。
     */
    public function findByNotifyNo(string $notifyNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('notify_no', $notifyNo)
            ->first($columns);
    }

    /**
     * 根据渠道、通知类型和业务单号查询重复通知记录。
     */
    public function findDuplicate(int $channelId, int $notifyType, string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('channel_id', $channelId)
            ->where('notify_type', $notifyType)
            ->where('biz_no', $bizNo)
            ->first($columns);
    }
}


