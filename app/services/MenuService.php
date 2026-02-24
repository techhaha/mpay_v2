<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\InternalServerException;
use support\Cache;

/**
 * 菜单相关业务服务
 *
 * 负责：
 * - 从 JSON / 配置文件读取系统菜单
 * - 使用 webman/cache 缓存菜单数据
 * - 构建路由树结构
 */
class MenuService extends BaseService
{
    /**
     * 缓存键：系统菜单
     * 注意：webman/cache (symfony/cache) 不允许 key 中包含 : 等特殊字符
     */
    private const CACHE_KEY_MENU = 'system_menu_all';

    /**
     * 获取前端路由（树形结构）
     *
     * @return array
     */
    public function getRouters(): array
    {
        $menus = $this->getSystemMenu();
        return $this->buildMenuTree($menus);
    }

    /**
     * 获取系统菜单数据
     * 仅从 JSON 文件 + 缓存中读取
     */
    protected function getSystemMenu(): array
    {
        $menus = Cache::get(self::CACHE_KEY_MENU);

        if (!is_array($menus)) {
            // 优先读取 JSON 文件
            $jsonPath = config_path('system-file/menu.json');
            if (!file_exists($jsonPath)) {
                throw new InternalServerException('菜单配置文件不存在');
            }

            $jsonContent = file_get_contents($jsonPath);
            $data = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                throw new InternalServerException('菜单配置文件格式错误：' . json_last_error_msg());
            }

            $menus = $data;
            Cache::set(self::CACHE_KEY_MENU, $menus);
        }

        return $menus;
    }

    /**
     * 构建菜单树形结构
     */
    protected function buildMenuTree(array $menus, string $parentId = '0'): array
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


