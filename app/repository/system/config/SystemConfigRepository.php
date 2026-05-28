<?php

namespace app\repository\system\config;

use app\common\base\BaseRepository;
use app\model\system\SystemConfig;

/**
 * 系统配置仓库。
 */
class SystemConfigRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new SystemConfig());
    }

    /**
     * 按配置键批量查询配置值。
     *
     * @param array<int, mixed> $keys 配置键列表
     * @return array<string, string> 配置键到配置值的映射
     */
    public function valueMapByKeys(array $keys): array
    {
        $normalizedKeys = [];
        foreach ($keys as $key) {
            $configKey = strtolower(trim((string) $key));
            if ($configKey !== '') {
                $normalizedKeys[$configKey] = true;
            }
        }

        $keys = array_keys($normalizedKeys);
        if ($keys === []) {
            return [];
        }

        $rows = $this->query()
            ->whereIn('config_key', $keys)
            ->get(['config_key', 'config_value']);

        $values = [];
        foreach ($rows as $row) {
            $values[strtolower((string) $row->config_key)] = (string) ($row->config_value ?? '');
        }

        return $values;
    }

    /**
     * 按配置键批量删除配置。
     *
     * @param array<int, string> $keys 配置键列表
     * @return int 删除数量
     */
    public function deleteByConfigKeys(array $keys): int
    {
        $normalizedKeys = [];
        foreach ($keys as $key) {
            $configKey = strtolower(trim((string) $key));
            if ($configKey !== '') {
                $normalizedKeys[$configKey] = true;
            }
        }

        $keys = array_keys($normalizedKeys);
        if ($keys === []) {
            return 0;
        }

        return (int) $this->query()
            ->whereIn('config_key', $keys)
            ->delete();
    }
}



