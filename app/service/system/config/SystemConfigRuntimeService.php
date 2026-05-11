<?php

namespace app\service\system\config;

use app\common\base\BaseService;
use app\repository\system\config\SystemConfigRepository;
use support\Cache;
use Throwable;

/**
 * 系统配置运行时服务。
 *
 * 负责读取、缓存和刷新当前进程可直接使用的系统配置值。
 * 读取结果以配置键到字符串值的映射形式提供给业务层。
 *
 * @property SystemConfigRepository $systemConfigRepository 系统配置仓库
 * @property SystemConfigDefinitionService $systemConfigDefinitionService 系统配置定义解析服务
 */
class SystemConfigRuntimeService extends BaseService
{
    protected const CACHE_KEY = 'system_config:all';

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
     * 获取全部系统配置运行时值。
     *
     * @param bool $refresh 是否强制刷新
     * @return array<string, string> 系统配置值映射
     */
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

    /**
     * 根据配置键获取运行时值。
     *
     * @param string $configKey 配置键
     * @param string|int|float|bool|null $default 默认值
     * @param bool $refresh 是否强制刷新
     * @return string 配置值字符串
     */
    public function get(string $configKey, string|int|float|bool|null $default = '', bool $refresh = false): string
    {
        $configKey = strtolower(trim($configKey));
        if ($configKey === '') {
            return (string) $default;
        }

        $values = $this->all($refresh);

        return (string) ($values[$configKey] ?? $default);
    }

    /**
     * 刷新系统配置运行时缓存。
     *
     * @return array<string, string> 最新配置值映射
     */
    public function refresh(): array
    {
        $values = $this->buildValueMap();
        $this->writeCache($values);

        return $values;
    }

    /**
     * 构建配置值映射。
     *
     * @return array<string, string> 配置值映射
     */
    protected function buildValueMap(): array
    {
        $values = $this->systemConfigDefinitionService->allDefaultStorageValues();
        if ($values === []) {
            return [];
        }

        foreach ($this->systemConfigRepository->valueMapByKeys(array_keys($values)) as $field => $value) {
            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * 读取运行时缓存。
     *
     * @return array<string, string>|null 缓存值
     */
    protected function readCache(): ?array
    {
        try {
            $raw = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($raw) ? $raw : null;
    }

    /**
     * 写入运行时缓存。
     *
     * @param array<string, string> $values 配置值映射
     * @return void
     */
    protected function writeCache(array $values): void
    {
        try {
            Cache::set(self::CACHE_KEY, $values);
        } catch (Throwable) {
            // Redis 不可用时不阻塞主流程。
        }
    }
}


