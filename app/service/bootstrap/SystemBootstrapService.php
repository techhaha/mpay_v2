<?php

namespace app\service\bootstrap;

use app\common\base\BaseService;

/**
 * 系统引导服务。
 *
 * 用于提供前端启动时需要的菜单树和字典项数据。
 */
class SystemBootstrapService extends BaseService
{
    /**
     * 获取指定面板的菜单树。
     *
     * @param string $panel 面板标识，通常为 `admin` 或 `merchant`
     * @return array 菜单树
     */
    public function getMenuTree(string $panel): array
    {
        $roles = $panel === 'merchant' ? ['common'] : ['admin'];
        $nodes = $this->filterByRoles($this->menuNodes($panel), $roles);

        return $this->normalizeRedirects($this->buildTree($nodes));
    }

    /**
     * 获取字典项。
     *
     * 支持一次获取全部字典，也支持按逗号分隔的 code 过滤。
     *
     * @param string|null $code 字典编码
     * @return array 字典数据
     */
    public function getDictItems(?string $code = null): array
    {
        $items = $this->dictItems();
        $code = trim((string) $code);
        if ($code === '') {
            return array_values($items);
        }

        $codes = array_values(array_filter(array_map('trim', explode(',', $code))));
        if ($codes === []) {
            return array_values($items);
        }

        if (count($codes) === 1) {
            return $items[$codes[0]] ?? [];
        }

        return array_values(array_intersect_key($items, array_flip($codes)));
    }

    /**
     * 获取面板菜单配置原始节点。
     *
     * @param string $panel 面板标识
     * @return array 原始节点
     */
    protected function menuNodes(string $panel): array
    {
        return (array) config("menu.$panel", config('menu.admin', []));
    }

    /**
     * 获取系统字典原始配置。
     *
     * @return array 原始字典配置
     */
    protected function dictItems(): array
    {
        return $this->normalizeDictItems((array) config('dict', []));
    }

    /**
     * 将系统字典配置标准化为 code 索引结构。
     *
     * @param array $items 原始配置
     * @return array 标准化后的字典项
     */
    protected function normalizeDictItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = trim((string) ($item['code'] ?? (is_string($key) ? $key : '')));
            if ($code === '') {
                continue;
            }

            $list = [];
            foreach ((array) ($item['list'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $list[] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'value' => $row['value'] ?? '',
                ];
            }

            $normalized[$code] = [
                'name' => (string) ($item['name'] ?? $code),
                'code' => $code,
                'description' => (string) ($item['description'] ?? ''),
                'list' => $list,
            ];
        }

        return $normalized;
    }

    /**
     * 按角色过滤菜单节点。
     *
     * @param array $nodes 菜单节点
     * @param array $roles 角色集合
     * @return array 过滤后的节点
     */
    protected function filterByRoles(array $nodes, array $roles): array
    {
        return array_values(array_filter($nodes, function (array $node) use ($roles): bool {
            $metaRoles = (array) ($node['meta']['roles'] ?? []);
            if ($metaRoles !== [] && count(array_intersect($metaRoles, $roles)) === 0) {
                return false;
            }

            if (!empty($node['meta']['disable'])) {
                return false;
            }

            return true;
        }));
    }

    /**
     * 将扁平菜单节点构造成树。
     *
     * @param array $nodes 菜单节点
     * @return array 树结构
     */
    protected function buildTree(array $nodes): array
    {
        $grouped = [];
        foreach ($nodes as $node) {
            $node['children'] = null;
            $parentId = (string) ($node['parentId'] ?? '0');
            $grouped[$parentId][] = $node;
        }

        $build = function (string $parentId) use (&$build, &$grouped): array {
            $children = $grouped[$parentId] ?? [];
            usort($children, function (array $left, array $right): int {
                $leftSort = (int) ($left['meta']['sort'] ?? 0);
                $rightSort = (int) ($right['meta']['sort'] ?? 0);

                return $leftSort <=> $rightSort;
            });

            foreach ($children as &$child) {
                $child['children'] = $build((string) $child['id']);
                if ($child['children'] === []) {
                    $child['children'] = null;
                }
            }

            return $children;
        };

        return $build('0');
    }

    /**
     * 为有子节点的菜单补充默认重定向路径。
     *
     * @param array $tree 菜单树
     * @return array 处理后的菜单树
     */
    protected function normalizeRedirects(array $tree): array
    {
        foreach ($tree as &$node) {
            if (!empty($node['children']) && is_array($node['children'])) {
                $childPath = $this->firstRenderablePath($node['children']);
                if ($childPath !== null) {
                    $node['redirect'] = $childPath;
                }
                $node['children'] = $this->normalizeRedirects($node['children']);
            }
        }

        return $tree;
    }

    /**
     * 获取首个可渲染路径。
     *
     * @param array $nodes 菜单节点
     * @return string|null 路径
     */
    protected function firstRenderablePath(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            $path = (string) ($node['path'] ?? '');
            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }
}






