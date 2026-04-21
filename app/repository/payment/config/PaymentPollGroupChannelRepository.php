<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPollGroupChannel;

/**
 * 轮询组与通道编排仓库。
 *
 * 封装轮询组下通道编排、默认通道清理等查询与更新。
 */
class PaymentPollGroupChannelRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentPollGroupChannel());
    }

    /**
     * 查询轮询组下的通道编排列表。
     *
     * @param int $pollGroupId 轮询分组ID
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, PaymentPollGroupChannel> 编排列表
     */
    public function listByPollGroupId(int $pollGroupId, array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('poll_group_id', $pollGroupId)
            ->where('status', 1)
            ->orderBy('sort_no')
            ->get($columns);
    }

    /**
     * 清空轮询组下其他默认通道标记。
     *
     * @param int $pollGroupId 轮询分组ID
     * @param int $ignoreId 需要保留默认标记的记录ID
     * @return int 受影响行数
     */
    public function clearDefaultExcept(int $pollGroupId, int $ignoreId = 0): int
    {
        $query = $this->model->newQuery()
            ->where('poll_group_id', $pollGroupId)
            ->where('is_default', 1);

        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }

        return (int) $query->update(['is_default' => 0]);
    }
}





