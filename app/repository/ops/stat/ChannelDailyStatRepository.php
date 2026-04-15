<?php

namespace app\repository\ops\stat;

use app\common\base\BaseRepository;
use app\model\admin\ChannelDailyStat;

/**
 * 通道日统计仓库。
 */
class ChannelDailyStatRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new ChannelDailyStat());
    }

    /**
     * 根据通道和日期查询统计记录。
     */
    public function findByChannelAndDate(int $channelId, string $statDate, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('channel_id', $channelId)
            ->where('stat_date', $statDate)
            ->first($columns);
    }
}


