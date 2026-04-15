<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use RuntimeException;

class SystemConfigDefinitionService extends BaseService
{
    protected const VIRTUAL_FIELD_PREFIX = '__';

    /**
     * 已解析的标签页缓存。
     */
    protected ?array $tabCache = null;

    /**
     * 标签页键到定义的缓存。
     */
    protected ?array $tabMapCache = null;

    public function tabs(): array
    {
        if ($this->tabCache !== null) {
            return $this->tabCache;
        }

        $definitions = (array) config('system_config', []);
        $tabs = [];
        $seenKeys = [];
        $seenFields = [];

        foreach ($definitions as $groupCode => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $tab = $this->normalizeTab((string) $groupCode, $definition);
            if ($tab === null) {
                continue;
            }

            $key = $tab['key'];
            if (isset($seenKeys[$key])) {
                throw new RuntimeException(sprintf('系统配置标签 key 重复：%s', $key));
            }

            foreach ($tab['rules'] as $rule) {
                $field = (string) ($rule['field'] ?? '');
                if ($field === '' || $this->isVirtualField($field)) {
                    continue;
                }

                if (isset($seenFields[$field])) {
                    throw new RuntimeException(sprintf('系统配置项 key 重复：%s', $field));
                }

                $seenFields[$field] = true;
            }

            $seenKeys[$key] = true;
            $tabs[] = $tab;
        }

        usort($tabs, static function (array $left, array $right): int {
            $leftSort = (int) ($left['sort'] ?? 0);
            $rightSort = (int) ($right['sort'] ?? 0);

            return $leftSort <=> $rightSort;
        });

        $this->tabCache = $tabs;
        $this->tabMapCache = [];

        foreach ($tabs as $tab) {
            $key = (string) ($tab['key'] ?? '');
            if ($key !== '') {
                $this->tabMapCache[$key] = $tab;
            }
        }

        return $this->tabCache;
    }

    public function tab(string $groupCode): ?array
    {
        $groupCode = strtolower(trim($groupCode));
        if ($groupCode === '') {
            return null;
        }

        $this->tabs();

        return $this->tabMapCache[$groupCode] ?? null;
    }

    public function hydrateRules(array $tab, array $values): array
    {
        $rules = [];
        foreach ((array) ($tab['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = (string) ($rule['field'] ?? '');
            if ($field === '') {
                continue;
            }

            if (!$this->isVirtualField($field)) {
                $rule['value'] = array_key_exists($field, $values) ? $values[$field] : ($rule['value'] ?? '');
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    public function extractFormData(array $tab, array $values): array
    {
        $data = [];
        foreach ((array) ($tab['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = (string) ($rule['field'] ?? '');
            if ($field === '' || $this->isVirtualField($field)) {
                continue;
            }

            $data[$field] = array_key_exists($field, $values) ? $values[$field] : ($rule['value'] ?? '');
        }

        return $data;
    }

    public function requiredFieldMessages(array $tab): array
    {
        $messages = [];
        foreach ((array) ($tab['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = strtolower(trim((string) ($rule['field'] ?? '')));
            if ($field === '' || $this->isVirtualField($field)) {
                continue;
            }

            foreach ((array) ($rule['validate'] ?? []) as $validateRule) {
                if (!is_array($validateRule)) {
                    continue;
                }

                if (!empty($validateRule['required'])) {
                    $messages[$field] = (string) ($validateRule['message'] ?? sprintf('%s 不能为空', (string) ($rule['title'] ?? $field)));
                    break;
                }
            }
        }

        return $messages;
    }

    private function normalizeTab(string $groupCode, array $definition): ?array
    {
        $key = strtolower(trim((string) ($definition['key'] ?? $groupCode)));
        if ($key === '') {
            return null;
        }

        $rules = [];
        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            $normalizedRule = $this->normalizeRule($rule);
            if ($normalizedRule !== null) {
                $rules[] = $normalizedRule;
            }
        }

        return [
            'key' => $key,
            'title' => (string) ($definition['title'] ?? $key),
            'icon' => (string) ($definition['icon'] ?? ''),
            'description' => (string) ($definition['description'] ?? ''),
            'sort' => (int) ($definition['sort'] ?? 0),
            'disabled' => (bool) ($definition['disabled'] ?? false),
            'submitText' => (string) ($definition['submitText'] ?? '保存配置'),
            'refreshAfterSubmit' => (bool) ($definition['refreshAfterSubmit'] ?? true),
            'rules' => $rules,
        ];
    }

    private function normalizeRule(mixed $rule): ?array
    {
        if (!is_array($rule)) {
            return null;
        }

        $field = strtolower(trim((string) ($rule['field'] ?? '')));
        if ($field === '') {
            return null;
        }

        $options = [];
        foreach ((array) ($rule['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }

            $options[] = [
                'label' => (string) ($option['label'] ?? ''),
                'value' => (string) ($option['value'] ?? ''),
            ];
        }

        $validate = [];
        foreach ((array) ($rule['validate'] ?? []) as $validateRule) {
            if (!is_array($validateRule)) {
                continue;
            }

            $validate[] = $validateRule;
        }

        $normalized = $rule;
        $normalized['type'] = (string) ($rule['type'] ?? 'input');
        $normalized['field'] = $field;
        $normalized['title'] = (string) ($rule['title'] ?? $field);
        $normalized['value'] = (string) ($rule['value'] ?? '');
        $normalized['props'] = is_array($rule['props'] ?? null) ? $rule['props'] : [];
        $normalized['options'] = $options;
        $normalized['validate'] = $validate;

        return $normalized;
    }

    private function isVirtualField(string $field): bool
    {
        return str_starts_with($field, self::VIRTUAL_FIELD_PREFIX);
    }
}
