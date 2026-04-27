<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use app\common\constant\EventConstant;
use app\exception\ValidationException;
use app\repository\system\config\SystemConfigRepository;
use Webman\Event\Event;

/**
 * 系统配置页面服务。
 *
 * 负责把配置定义和数据库中的配置值组装成页面所需的数据结构。
 * 典型流程是先读取定义，再回填当前值，最后生成表单页数据。
 *
 * @property SystemConfigRepository $systemConfigRepository 系统配置仓库
 * @property SystemConfigDefinitionService $systemConfigDefinitionService 系统配置定义解析服务
 */
class SystemConfigPageService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param SystemConfigRepository $systemConfigRepository 系统配置仓库
     * @param SystemConfigDefinitionService $systemConfigDefinitionService 系统配置定义解析服务
     */
    public function __construct(
        protected SystemConfigRepository $systemConfigRepository,
        protected SystemConfigDefinitionService $systemConfigDefinitionService
    ) {
    }

    /**
     * 获取系统配置页面标签页列表。
     *
     * @return array<string, mixed> 页面所需标签页数据
     */
    public function tabs(): array
    {
        $tabs = [];
        foreach ($this->systemConfigDefinitionService->tabs() as $tab) {
            unset($tab['rules']);
            $tabs[] = $tab;
        }

        $defaultKey = '';
        foreach ($tabs as $tab) {
            if (!empty($tab['disabled'])) {
                continue;
            }

            $defaultKey = (string) ($tab['key'] ?? '');
            if ($defaultKey !== '') {
                break;
            }
        }

        return [
            'defaultKey' => $defaultKey !== '' ? $defaultKey : (string) ($tabs[0]['key'] ?? ''),
            'tabs' => $tabs,
        ];
    }

    /**
     * 查询系统配置页面详情。
     *
     * @param string $groupCode 分组代码
     * @return array<string, mixed> 页面详情
     * @throws ValidationException
     */
    public function detail(string $groupCode): array
    {
        $tab = $this->systemConfigDefinitionService->tab($groupCode);
        if (!$tab) {
            throw new ValidationException('系统配置标签不存在');
        }

        $keys = [];
        foreach ((array) ($tab['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = strtolower(trim((string) ($rule['field'] ?? '')));
            if ($field !== '' && !str_starts_with($field, '__')) {
                $keys[] = $field;
            }
        }

        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            $rowMap = [];
        } else {
            $rows = $this->systemConfigRepository->query()
                ->whereIn('config_key', $keys)
                ->get(['config_key', 'config_value']);

            $rowMap = [];
            foreach ($rows as $row) {
                $rowMap[strtolower((string) $row->config_key)] = (string) ($row->config_value ?? '');
            }
        }

        $tab['rules'] = $this->systemConfigDefinitionService->hydrateRules($tab, $rowMap);
        $tab['formData'] = $this->systemConfigDefinitionService->extractFormData($tab, $rowMap);

        return $tab;
    }

    /**
     * 保存系统配置页面。
     *
     * @param string $groupCode 分组代码
     * @param array<string, mixed> $values 提交值
     * @return array<string, mixed> 保存后的页面详情
     * @throws ValidationException
     */
    public function save(string $groupCode, array $values): array
    {
        $tab = $this->systemConfigDefinitionService->tab($groupCode);
        if (!$tab) {
            throw new ValidationException('系统配置标签不存在');
        }

        $formData = $this->systemConfigDefinitionService->extractFormData($tab, $values);
        $this->validateRequiredValues($tab, $formData);

        $this->transaction(function () use ($tab, $formData): void {
            foreach ((array) ($tab['rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $field = strtolower(trim((string) ($rule['field'] ?? '')));
                if ($field === '' || str_starts_with($field, '__')) {
                    continue;
                }

                $value = $this->stringifyValue($formData[$field] ?? '');
                $this->systemConfigRepository->updateOrCreate(
                    ['config_key' => $field],
                    [
                        'group_code' => (string) $tab['key'],
                        'config_value' => $value,
                    ]
                );
            }
        });

        Event::dispatch(EventConstant::SYSTEM_CONFIG_CHANGED, [
            'group_code' => (string) $tab['key'],
        ]);

        return $this->detail((string) $tab['key']);
    }

    /**
     * 校验必填配置项。
     *
     * @param array<string, mixed> $tab 标签页定义
     * @param array<string, mixed> $values 配置值
     * @return void
     * @throws ValidationException
     */
    protected function validateRequiredValues(array $tab, array $values): void
    {
        $messages = $this->systemConfigDefinitionService->requiredFieldMessages($tab);

        foreach ($messages as $field => $message) {
            $value = $values[$field] ?? '';
            if ($this->isEmptyValue($value)) {
                throw new ValidationException($message);
            }
        }
    }

    /**
     * 判断配置值是否为空。
     *
     * @param array|object|bool|float|int|string|null $value 配置值
     * @return bool 是否为空
     */
    protected function isEmptyValue(array|object|bool|float|int|string|null $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null || $value === '';
    }

    /**
     * 将配置值转换为可保存字符串。
     *
     * @param array|object|bool|float|int|string|null $value 配置值
     * @return string 可保存字符串
     * @throws ValidationException
     */
    protected function stringifyValue(array|object|bool|float|int|string|null $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            throw new ValidationException('系统配置值暂不支持复杂类型');
        }

        return (string) $value;
    }
}
