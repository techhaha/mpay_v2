<?php

namespace app\repository\ops\stat;

use app\common\base\BaseRepository;
use app\model\admin\ChannelDailyStat;

/**
 * 通道日统计仓库。
 *
 * 封装按通道和日期读取日统计记录。
 */
class ChannelDailyStatRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new ChannelDailyStat());
    }

    /**
     * 根据通道和日期查询统计记录。
     *
     * @param int $channelId 渠道ID
     * @param string $statDate 统计日期
     * @param array $columns 字段列表
     * @return ChannelDailyStat|null 统计记录
     */
    public function findByChannelAndDate(int $channelId, string $statDate, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('channel_id', $channelId)
            ->where('stat_date', $statDate)
            ->first($columns);
    }
}






