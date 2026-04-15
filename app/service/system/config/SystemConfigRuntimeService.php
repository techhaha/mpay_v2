<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use app\repository\system\config\SystemConfigRepository;
use support\Cache;
use Throwable;

class SystemConfigRuntimeService extends BaseService
{
    protected const CACHE_KEY = 'system_config:all';

    public function __construct(
        protected SystemConfigRepository $systemConfigRepository,
        protected SystemConfigDefinitionService $systemConfigDefinitionService
    ) {
    }

    public function all(bool $refresh = false): array
    {
        if (!$refresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->refresh();
    }

    public function get(string $configKey, mixed $default = '', bool $refresh = false): string
    {
        $configKey = strtolower(trim($configKey));
        if ($configKey === '') {
            return (string) $default;
        }

        $values = $this->all($refresh);

        return (string) ($values[$configKey] ?? $default);
    }

    public function refresh(): array
    {
        $values = $this->buildValueMap();
        $this->writeCache($values);

        return $values;
    }

    protected function buildValueMap(): array
    {
        $values = [];
        $tabs = $this->systemConfigDefinitionService->tabs();
        $keys = [];

        foreach ($tabs as $tab) {
            foreach ((array) ($tab['rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $field = strtolower(trim((string) ($rule['field'] ?? '')));
                if ($field !== '' && !str_starts_with($field, '__')) {
                    $keys[] = $field;
                }
            }
        }

        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return [];
        }

        $rows = $this->systemConfigRepository->query()
            ->whereIn('config_key', $keys)
            ->get(['config_key', 'config_value']);

        $rowMap = [];
        foreach ($rows as $row) {
            $rowMap[strtolower((string) $row->config_key)] = (string) ($row->config_value ?? '');
        }

        foreach ($tabs as $tab) {
            foreach ((array) ($tab['rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $field = strtolower(trim((string) ($rule['field'] ?? '')));
                if ($field === '' || str_starts_with($field, '__')) {
                    continue;
                }

                $values[$field] = array_key_exists($field, $rowMap)
                    ? (string) $rowMap[$field]
                    : (string) ($rule['value'] ?? '');
            }
        }

        return $values;
    }

    protected function readCache(): ?array
    {
        try {
            $raw = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($raw) ? $raw : null;
    }

    protected function writeCache(array $values): void
    {
        try {
            Cache::set(self::CACHE_KEY, $values);
        } catch (Throwable) {
            // Redis 不可用时不阻塞主流程。
        }
    }
}
