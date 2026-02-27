<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\Menu;

/**
 * 菜单 / 权限仓储
 */
class MenuRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Menu());
    }

    /**
     * 获取所有启用的菜单（仅目录和菜单类型，排除按钮）
     */
    public function getAllEnabledMenus(): array
    {
        return $this->model
            ->newQuery()
            ->whereIn('type', [1, 2]) // 1目录 2菜单，排除3按钮
            ->where('status', 1) // 只获取启用的菜单
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * 根据菜单ID列表获取启用的菜单（仅目录和菜单类型，排除按钮）
     */
    public function getMenusByIds(array $menuIds): array
    {
        if (empty($menuIds)) {
            return [];
        }

        return $this->model
            ->newQuery()
            ->whereIn('id', $menuIds)
            ->whereIn('type', [1, 2]) // 1目录 2菜单，排除3按钮
            ->where('status', 1) // 只获取启用的菜单
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->toArray();
    }
}


