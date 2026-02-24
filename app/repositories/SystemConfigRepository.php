<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\SystemConfig;
use support\Cache;
use Webman\Event\Event;

/**
 * 系统配置仓储
 */
class SystemConfigRepository extends BaseRepository
{
    /**
     * 缓存键：全部系统配置
     */
    private const CACHE_KEY_ALL_CONFIG = 'system_config_all';

    public function __construct()
    {
        parent::__construct(new SystemConfig());
    }

    /**
     * 从数据库加载所有配置到缓存
     *
     * @return array<string, string>
     */
    protected function loadAllToCache(): array
    {
        // 优先从 webman/cache 获取
        $cached = Cache::get(self::CACHE_KEY_ALL_CONFIG);
        if (is_array($cached)) {
            return $cached;
        }

        // 缓存不存在时从数据库加载
        $configs = $this->model
            ->newQuery()
            ->get(['config_key', 'config_value']);

        $result = [];
        foreach ($configs as $config) {
            $result[$config->config_key] = $config->config_value;
        }

        // 写入缓存（不过期，除非显式清理）
        Cache::set(self::CACHE_KEY_ALL_CONFIG, $result);

        return $result;
    }

    /**
     * 清空缓存（供事件调用）
     */
    public static function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY_ALL_CONFIG);
    }

    /**
     * 重新从数据库加载缓存（供事件调用）
     */
    public function reloadCache(): void
    {
        Cache::delete(self::CACHE_KEY_ALL_CONFIG);
        $this->loadAllToCache();
    }

    /**
     * 根据配置键名查询配置值
     *
     * @param string $configKey
     * @return string|null
     */
    public function getValueByKey(string $configKey): ?string
    {
        $all = $this->loadAllToCache();

        return $all[$configKey] ?? null;
    }

    /**
     * 根据配置键名数组批量查询配置
     *
     * @param array $configKeys
     * @return array 返回 ['config_key' => 'config_value'] 格式的数组
     */
    public function getValuesByKeys(array $configKeys): array
    {
        if (empty($configKeys)) {
            return [];
        }

        $all = $this->loadAllToCache();

        $result = [];
        foreach ($configKeys as $key) {
            if (array_key_exists($key, $all)) {
                $result[$key] = $all[$key];
            }
        }

        return $result;
    }

    /**
     * 根据配置键名更新或创建配置
     *
     * @param string $configKey
     * @param string $configValue
     * @return bool
     */
    public function updateOrCreate(string $configKey, string $configValue): bool
    {
        $this->model
            ->newQuery()
            ->updateOrCreate(
                ['config_key' => $configKey],
                ['config_value' => $configValue]
            );

        // 通过事件通知重新加载缓存
        Event::emit('system.config.updated', null);

        return true;
    }

    /**
     * 批量更新或创建配置
     *
     * @param array $configs 格式：['config_key' => 'config_value']
     * @return bool
     */
    public function batchUpdateOrCreate(array $configs): bool
    {
        if (empty($configs)) {
            return true;
        }

        foreach ($configs as $configKey => $configValue) {
            $this->model
                ->newQuery()
                ->updateOrCreate(
                    ['config_key' => $configKey],
                    ['config_value' => $configValue]
                );
        }

        // 批量更新后只触发一次事件，通知重新加载缓存
        Event::emit('system.config.updated', null);

        return true;
    }
}

