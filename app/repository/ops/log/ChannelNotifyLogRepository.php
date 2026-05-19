<?php

namespace app\repository\ops\log;

use app\common\base\BaseRepository;
use app\model\admin\ChannelNotifyLog;

/**
 * 渠道通知日志仓库。
 *
 * 封装通知单号查询与重复通知识别。
 */
class ChannelNotifyLogRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new ChannelNotifyLog());
    }

    /**
     * 根据通知单号查询渠道通知日志。
     *
     * @param string $notifyNo 通知号
     * @param array $columns 字段列表
     * @return ChannelNotifyLog|null 日志记录
     */
    public function findByNotifyNo(string $notifyNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('notify_no', $notifyNo)
            ->first($columns);
    }

    /**
     * 根据渠道、通知类型和业务单号查询重复通知记录。
     *
     * @param int $channelId 渠道ID
     * @param int $notifyType 通知类型
     * @param string $bizNo 业务单号
     * @param array $columns 字段列表
     * @return ChannelNotifyLog|null 日志记录
     */
    public function findDuplicate(int $channelId, int $notifyType, string $bizNo, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('channel_id', $channelId)
            ->where('notify_type', $notifyType)
            ->where('biz_no', $bizNo)
            ->first($columns);
    }

    /**
     * 查询指定支付单和通知类型的渠道日志。
     *
     * @param string $payNo 支付单号
     * @param int $notifyType 通知类型
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, ChannelNotifyLog> 日志列表
     */
    public function listByPayNoAndType(string $payNo, int $notifyType, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('pay_no', $payNo)
            ->where('notify_type', $notifyType)
            ->orderByDesc('id')
            ->get($columns);
    }
}





