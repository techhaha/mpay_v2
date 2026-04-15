<?php

namespace app\repository\merchant\base;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantGroup;

/**
 * 商户分组仓库。
 */
class MerchantGroupRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new MerchantGroup());
    }

    /**
     * 获取所有启用的商户分组。
     */
    public function enabledList(array $columns = ['*'])
    {
        return $this->model->newQuery()
            ->where('status', 1)
            ->orderBy('id', 'asc')
            ->get($columns);
    }

    /**
     * 判断分组名称是否已存在。
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

