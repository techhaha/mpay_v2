<?php

namespace app\service\bootstrap;

use app\common\base\BaseService;

class SystemBootstrapService extends BaseService
{
    public function getMenuTree(string $panel): array
    {
        $roles = $panel === 'merchant' ? ['common'] : ['admin'];
        $nodes = $this->filterByRoles($this->menuNodes($panel), $roles);

        return $this->normalizeRedirects($this->buildTree($nodes));
    }

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

    protected function menuNodes(string $panel): array
    {
        return (array) config("menu.$panel", config('menu.admin', []));
    }

    protected function dictItems(): array
    {
        return $this->normalizeDictItems((array) config('dict', []));
    }

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

