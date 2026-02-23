<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use support\Request;

/**
 * 菜单控制器
 */
class MenuController extends BaseController
{
    public function getRouters()
    {
        // 获取菜单数据并转换为树形结构
        $routers = $this->buildMenuTree($this->getSystemMenu());
        
        return $this->success($routers);
    }

    /**
     * 获取系统菜单数据
     * 从配置文件读取
     */
    private function getSystemMenu(): array
    {
        return config('menu', []);
    }

    /**
     * 构建菜单树形结构
     */
    private function buildMenuTree(array $menus, string $parentId = '0'): array
    {
        $tree = [];

        foreach ($menus as $menu) {
            if (($menu['parentId'] ?? '0') === $parentId) {
                $children = $this->buildMenuTree($menus, $menu['id']);
                $menu['children'] = !empty($children) ? $children : null;
                $tree[] = $menu;
            }
        }

        // 按 sort 排序
        usort($tree, function ($a, $b) {
            return ($a['meta']['sort'] ?? 0) <=> ($b['meta']['sort'] ?? 0);
        });

        return $tree;
    }
}

