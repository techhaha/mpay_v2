<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use app\exception\ConflictException;
use app\exception\ValidationException;

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
     * @throws ConflictException
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
                throw new ConflictException(sprintf('系统配置标签 key 重复：%s', $key), [
                    'key' => $key,
                ]);
            }

            foreach ($tab['rules'] as $rule) {
                $field = (string) ($rule['field'] ?? '');
                if ($field === '' || $this->isVirtualField($field)) {
                    continue;
                }

                if (isset($seenFields[$field])) {
                    throw new ConflictException(sprintf('系统配置项 key 重复：%s', $field), [
                        'field' => $field,
                    ]);
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
     * 获取标签页内的实际配置字段。
     *
     * @param array $tab 标签页定义
     * @return array<int, string> 配置字段列表
     */
    public function fields(array $tab): array
    {
        $fields = [];
        foreach ((array) ($tab['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = strtolower(trim((string) ($rule['field'] ?? '')));
            if ($field === '' || $this->isVirtualField($field)) {
                continue;
            }

            $fields[$field] = true;
        }

        return array_keys($fields);
    }

    /**
     * 获取全部实际配置字段。
     *
     * @return array<int, string> 配置字段列表
     */
    public function allFields(): array
    {
        $fields = [];
        foreach ($this->tabs() as $tab) {
            foreach ($this->fields($tab) as $field) {
                $fields[$field] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * 获取标签页内配置项的默认落库值。
     *
     * @param array $tab 标签页定义
     * @return array<string, string> 字段到默认值的映射
     * @throws ValidationException
     */
    public function defaultStorageValues(array $tab): array
    {
        $defaults = [];
        foreach ((array) ($tab['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = strtolower(trim((string) ($rule['field'] ?? '')));
            if ($field === '' || $this->isVirtualField($field)) {
                continue;
            }

            $defaults[$field] = $this->stringifyValue($rule['value'] ?? '');
        }

        return $defaults;
    }

    /**
     * 获取全部配置项默认落库值。
     *
     * @return array<string, string> 字段到默认值的映射
     * @throws ValidationException
     */
    public function allDefaultStorageValues(): array
    {
        $defaults = [];
        foreach ($this->tabs() as $tab) {
            foreach ($this->defaultStorageValues($tab) as $field => $value) {
                $defaults[$field] = $value;
            }
        }

        return $defaults;
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
                $rule['value'] = $this->normalizeValueForForm(
                    $rule,
                    array_key_exists($field, $values) ? $values[$field] : ($rule['value'] ?? '')
                );
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

            $data[$field] = $this->normalizeValueForForm(
                $rule,
                array_key_exists($field, $values) ? $values[$field] : ($rule['value'] ?? '')
            );
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
     * 将配置值转换为可落库字符串。
     *
     * @param mixed $value 配置值
     * @return string 可落库字符串
     * @throws ValidationException
     */
    public function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            throw new ValidationException('系统配置值暂不支持复杂类型');
        }

        return (string) $value;
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
                'value' => $option['value'] ?? '',
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
        $normalized['value'] = $rule['value'] ?? '';
        $normalized['props'] = is_array($rule['props'] ?? null) ? $rule['props'] : [];
        $normalized['options'] = $options;
        $normalized['validate'] = $validate;

        return $normalized;
    }

    /**
     * 按表单组件类型整理前端模型值。
     *
     * @param array<string, mixed> $rule 配置项定义
     * @param mixed $value 原始值
     * @return mixed 前端表单值
     */
    private function normalizeValueForForm(array $rule, mixed $value): mixed
    {
        $type = strtolower(trim((string) ($rule['type'] ?? '')));
        if ($type !== 'inputnumber') {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return null;
    }

    /**
     * 判断是否为虚拟字段。
     *
     * @param string $field 字段名
     * @return bool 是否为虚拟字段
     */
    public function isVirtualField(string $field): bool
    {
        return str_starts_with(trim($field), self::VIRTUAL_FIELD_PREFIX);
    }
}
