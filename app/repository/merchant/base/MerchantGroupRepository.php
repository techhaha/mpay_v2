<?php

namespace app\repository\merchant\base;

use app\common\base\BaseRepository;
use app\model\merchant\MerchantGroup;

/**
 * 商户分组仓库。
 *
 * 封装商户分组启用列表和唯一性检查。
 */
class MerchantGroupRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new MerchantGroup());
    }

    /**
     * 获取所有启用的商户分组。
     *
     * @param array $columns 字段列表
     * @return \Illuminate\Database\Eloquent\Collection<int, MerchantGroup> 启用分组列表
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
     *
     * @param string $groupName 分组名称
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





