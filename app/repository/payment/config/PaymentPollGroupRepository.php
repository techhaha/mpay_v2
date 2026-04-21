<?php

namespace app\repository\payment\config;

use app\common\base\BaseRepository;
use app\model\payment\PaymentPollGroup;

/**
 * 支付轮询组仓库。
 */
class PaymentPollGroupRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new PaymentPollGroup());
    }

    /**
     * 判断轮询组名称是否已存在。
     *
     * @param string $groupName 轮询组名称
     * @param int $ignoreId 需要排除的记录ID
     * @return bool 是否存在
     */
    public function existsByGroupName(string $groupName, int $ignoreId = 0): bool
    {
        $query = $this->model->newQuery()
            ->where('group_name', $groupName);

        if ($ignoreId > 0) {
            $query->where('id', '<>', $ignoreId);
        }

        return $query->exists();
    }
}




