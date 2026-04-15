<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use app\exception\ValidationException;
use app\repository\system\config\SystemConfigRepository;
use Webman\Event\Event;

class SystemConfigPageService extends BaseService
{
    public function __construct(
        protected SystemConfigRepository $systemConfigRepository,
        protected SystemConfigDefinitionService $systemConfigDefinitionService
    ) {
    }

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

        Event::emit('system.config.changed', [
            'group_code' => (string) $tab['key'],
        ]);

        return $this->detail((string) $tab['key']);
    }

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

    protected function isEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null || $value === '';
    }

    protected function stringifyValue(mixed $value): string
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
