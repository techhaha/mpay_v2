<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPollGroupChannel;

/**
 * 轮询组与通道编排仓库。
 */
class PaymentPollGroupChannelRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new PaymentPollGroupChannel());
    }

    /**
     * 查询轮询组下的通道编排列表。
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

