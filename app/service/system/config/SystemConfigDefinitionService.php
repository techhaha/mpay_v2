<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use RuntimeException;

/**
 * 系统配置定义解析服务。
 *
 * 负责读取 `system_config` 配置并标准化为标签页、规则和默认值结构。
 */
class SystemConfigDefinitionService extends BaseService
{
    protected const VIRTUAL_FIELD_PREFIX = '__';

    /**
     * 已解析的标签页缓存。
     *
     * @var array|null
     */
    protected ?array $tabCache = null;

    /**
     * 标签页键到定义的缓存。
     *
     * @var array|null
     */
    protected ?array $tabMapCache = null;

    /**
     * 获取全部系统配置标签页。
     *
     * @return array 标签页列表
     * @throws RuntimeException
     */
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

    /**
     * 根据分组代码获取标签页定义。
     *
     * @param string $groupCode 分组代码
     * @return array|null 标签页定义
     */
    public function tab(string $groupCode): ?array
    {
        $groupCode = strtolower(trim($groupCode));
        if ($groupCode === '') {
            return null;
        }

        $this->tabs();

        return $this->tabMapCache[$groupCode] ?? null;
    }

    /**
     * 使用当前值回填标签页规则。
     *
     * @param array $tab 标签页定义
     * @param array $values 当前值映射
     * @return array 回填后的规则列表
     */
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

    /**
     * 从标签页规则中提取表单提交数据。
     *
     * @param array $tab 标签页定义
     * @param array $values 当前值映射
     * @return array 表单数据
     */
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

    /**
     * 生成必填字段校验消息。
     *
     * @param array $tab 标签页定义
     * @return array 字段到错误消息的映射
     */
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

    /**
     * 标准化单个标签页定义。
     *
     * @param string $groupCode 分组代码
     * @param array $definition 原始定义
     * @return array|null 标准化后的标签页
     */
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

    /**
     * 标准化单个配置项定义。
     *
     * @param array|object|null $rule 原始规则
     * @return array|null 标准化后的规则
     */
    private function normalizeRule(array|object|null $rule): ?array
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

    /**
     * 判断是否为虚拟字段。
     *
     * @param string $field 字段名
     * @return bool 是否为虚拟字段
     */
    private function isVirtualField(string $field): bool
    {
        return str_starts_with($field, self::VIRTUAL_FIELD_PREFIX);
    }
}

