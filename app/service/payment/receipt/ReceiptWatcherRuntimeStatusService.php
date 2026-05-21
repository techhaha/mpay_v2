<?php

namespace app\service\payment\receipt;

use app\common\base\BaseService;
use support\Redis;
use Throwable;

/**
 * 网页流水监听工具运行状态服务。
 *
 * 该服务只读取 Python receipt_watcher 写入 Redis 的能力心跳，用于管理后台运行监控。
 * 业务订单同步、流水消费和支付确认仍由 ReceiptWatcherService 与队列消费链路负责。
 */
class ReceiptWatcherRuntimeStatusService extends BaseService
{
    private const INSTANCES_KEY = 'receipt_watcher_instances';
    private const INSTANCE_KEY_PREFIX = 'receipt_watcher_instance_';
    private const HEARTBEAT_MAX_AGE = 60;

    /**
     * 获取网页监听工具运行总览。
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        try {
            return $this->buildOverview();
        } catch (Throwable $e) {
            return [
                'enabled' => $this->watcherEnabled(),
                'status' => 'failed',
                'status_text' => '状态读取失败',
                'summary_value' => '异常',
                'tone' => 'danger',
                'message' => $e->getMessage(),
                'live_instances' => 0,
                'stale_instances' => 0,
                'configured_count' => count($this->configuredPluginCodes()),
                'supported_count' => 0,
                'missing_count' => 0,
                'configured_plugins' => $this->configuredPluginCodes(),
                'missing_plugins' => [],
                'extra_plugins' => [],
                'plugins' => [],
                'instances' => [],
            ];
        }
    }

    /**
     * 构建运行总览。
     *
     * @return array<string, mixed>
     */
    private function buildOverview(): array
    {
        $enabled = $this->watcherEnabled();
        $configuredCodes = $this->configuredPluginCodes();
        $instances = $this->instances();
        $liveInstances = array_values(array_filter(
            $instances,
            static fn (array $instance): bool => ($instance['status'] ?? '') === 'running'
        ));
        $supportedPlugins = $this->supportedPlugins($liveInstances);
        $supportedCodes = array_column($supportedPlugins, 'code');
        $missingCodes = $enabled ? array_values(array_diff($configuredCodes, $supportedCodes)) : [];
        $extraCodes = array_values(array_diff($supportedCodes, $configuredCodes));
        $status = $this->status($enabled, count($liveInstances), count($configuredCodes), $missingCodes);

        return [
            'enabled' => $enabled,
            'status' => $status['status'],
            'status_text' => $status['status_text'],
            'summary_value' => $status['summary_value'],
            'tone' => $status['tone'],
            'message' => $status['message'],
            'live_instances' => count($liveInstances),
            'stale_instances' => count($instances) - count($liveInstances),
            'configured_count' => count($configuredCodes),
            'supported_count' => count($supportedPlugins),
            'missing_count' => count($missingCodes),
            'configured_plugins' => $configuredCodes,
            'missing_plugins' => $missingCodes,
            'extra_plugins' => $extraCodes,
            'plugins' => $this->pluginRows($configuredCodes, $supportedPlugins, $enabled),
            'instances' => $instances,
        ];
    }

    /**
     * 读取 Python watcher 实例列表。
     *
     * @return array<int, array<string, mixed>>
     */
    private function instances(): array
    {
        $ids = Redis::zRange(self::INSTANCES_KEY, 0, -1);
        if (!is_array($ids) || $ids === []) {
            return [];
        }

        $now = time();
        $rows = [];
        foreach ($ids as $id) {
            $instanceId = $this->safeKeyPart((string) $id);
            $raw = Redis::get(self::INSTANCE_KEY_PREFIX . $instanceId);
            if (!is_string($raw) || $raw === '') {
                $rows[] = $this->staleInstance($instanceId);
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $rows[] = $this->staleInstance($instanceId);
                continue;
            }

            $lastSeenAt = (int) ($data['last_seen_at'] ?? 0);
            $age = $lastSeenAt > 0 ? max(0, $now - $lastSeenAt) : null;
            $running = $age !== null && $age <= self::HEARTBEAT_MAX_AGE;
            $plugins = $this->normalizePlugins((array) ($data['plugins'] ?? []), $instanceId);

            $rows[] = [
                'instance_id' => $instanceId,
                'hostname' => (string) ($data['hostname'] ?? ''),
                'pid' => (int) ($data['pid'] ?? 0),
                'status' => $running ? 'running' : 'timeout',
                'status_text' => $running ? '在线' : '心跳超时',
                'tone' => $running ? 'success' : 'warning',
                'started_at' => (int) ($data['started_at'] ?? 0),
                'started_at_text' => $this->timestampText((int) ($data['started_at'] ?? 0)),
                'last_seen_at' => $lastSeenAt,
                'last_seen_at_text' => $this->timestampText($lastSeenAt),
                'heartbeat_age_text' => $age === null ? '未上报' : $this->durationText($age) . '前',
                'poll_interval_seconds' => (float) ($data['poll_interval_seconds'] ?? 0),
                'account_fetch_limit' => (int) ($data['account_fetch_limit'] ?? 0),
                'account_concurrency' => (int) ($data['account_concurrency'] ?? 0),
                'plugin_count' => count($plugins),
                'plugins_text' => implode('、', array_column($plugins, 'code')),
                'plugins' => $plugins,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return ((int) ($b['last_seen_at'] ?? 0)) <=> ((int) ($a['last_seen_at'] ?? 0));
        });

        return $rows;
    }

    /**
     * 构建过期实例行。
     *
     * @param string $instanceId 实例标识
     * @return array<string, mixed>
     */
    private function staleInstance(string $instanceId): array
    {
        return [
            'instance_id' => $instanceId,
            'hostname' => '',
            'pid' => 0,
            'status' => 'stale',
            'status_text' => '已离线',
            'tone' => 'gray',
            'started_at' => 0,
            'started_at_text' => '—',
            'last_seen_at' => 0,
            'last_seen_at_text' => '已过期',
            'heartbeat_age_text' => '已过期',
            'poll_interval_seconds' => 0,
            'account_fetch_limit' => 0,
            'account_concurrency' => 0,
            'plugin_count' => 0,
            'plugins_text' => '—',
            'plugins' => [],
        ];
    }

    /**
     * 聚合在线实例支持的插件。
     *
     * @param array<int, array<string, mixed>> $instances 在线实例
     * @return array<int, array<string, mixed>>
     */
    private function supportedPlugins(array $instances): array
    {
        $plugins = [];
        foreach ($instances as $instance) {
            foreach ((array) ($instance['plugins'] ?? []) as $plugin) {
                $code = trim((string) ($plugin['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                if (!isset($plugins[$code])) {
                    $plugins[$code] = [
                        'code' => $code,
                        'name' => (string) ($plugin['name'] ?? $code),
                        'class' => (string) ($plugin['class'] ?? ''),
                        'concurrency_values' => [],
                        'features' => [],
                        'instances' => [],
                    ];
                }

                $plugins[$code]['instances'][] = (string) ($instance['instance_id'] ?? '');
                $plugins[$code]['concurrency_values'][] = (int) ($plugin['concurrency'] ?? 0);
                foreach ((array) ($plugin['features'] ?? []) as $feature) {
                    $feature = trim((string) $feature);
                    if ($feature !== '') {
                        $plugins[$code]['features'][] = $feature;
                    }
                }
            }
        }

        foreach ($plugins as &$plugin) {
            $plugin['instances'] = array_values(array_unique(array_filter($plugin['instances'])));
            $plugin['features'] = array_values(array_unique($plugin['features']));
            $plugin['concurrency_values'] = array_values(array_unique(array_filter($plugin['concurrency_values'])));
            $plugin['instance_count'] = count($plugin['instances']);
            $plugin['concurrency_text'] = $plugin['concurrency_values'] === []
                ? '—'
                : implode(' / ', array_map('strval', $plugin['concurrency_values']));
            $plugin['features_text'] = $this->featuresText($plugin['features']);
        }
        unset($plugin);

        return array_values($plugins);
    }

    /**
     * 构建插件对照表。
     *
     * @param array<int, string> $configuredCodes 后台配置插件
     * @param array<int, array<string, mixed>> $supportedPlugins watcher 支持插件
     * @param bool $enabled 总开关是否开启
     * @return array<int, array<string, mixed>>
     */
    private function pluginRows(array $configuredCodes, array $supportedPlugins, bool $enabled): array
    {
        $supportedByCode = [];
        foreach ($supportedPlugins as $plugin) {
            $supportedByCode[(string) $plugin['code']] = $plugin;
        }

        $codes = array_values(array_unique(array_merge($configuredCodes, array_keys($supportedByCode))));
        $rows = [];
        foreach ($codes as $code) {
            $configured = in_array($code, $configuredCodes, true);
            $supported = isset($supportedByCode[$code]);
            $plugin = $supportedByCode[$code] ?? ['code' => $code, 'name' => $code];
            $status = $this->pluginStatus($enabled, $configured, $supported);

            $rows[] = array_merge($plugin, [
                'code' => $code,
                'configured' => $configured,
                'configured_text' => $configured ? ($enabled ? '已启用' : '已配置') : '未配置',
                'supported' => $supported,
                'supported_text' => $supported ? '已支持' : '未上报',
                'status' => $status['status'],
                'status_text' => $status['status_text'],
                'tone' => $status['tone'],
            ]);
        }

        return $rows;
    }

    /**
     * 归一化实例上报插件。
     *
     * @param array<int, mixed> $plugins 原始插件列表
     * @param string $instanceId 实例标识
     * @return array<int, array<string, mixed>>
     */
    private function normalizePlugins(array $plugins, string $instanceId): array
    {
        $rows = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }

            $code = trim((string) ($plugin['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $features = [];
            foreach ((array) ($plugin['features'] ?? []) as $feature) {
                $feature = trim((string) $feature);
                if ($feature !== '') {
                    $features[] = $feature;
                }
            }

            $rows[] = [
                'code' => $code,
                'name' => (string) ($plugin['name'] ?? $code),
                'class' => (string) ($plugin['class'] ?? ''),
                'concurrency' => (int) ($plugin['concurrency'] ?? 0),
                'features' => array_values(array_unique($features)),
                'features_text' => $this->featuresText($features),
                'status' => (string) ($plugin['status'] ?? 'available'),
                'instance_id' => $instanceId,
            ];
        }

        return $rows;
    }

    /**
     * 运行状态。
     *
     * @param bool $enabled 是否启用
     * @param int $liveInstances 在线实例数
     * @param int $configuredCount 已配置插件数
     * @param array<int, string> $missingCodes 缺失插件
     * @return array<string, string>
     */
    private function status(bool $enabled, int $liveInstances, int $configuredCount, array $missingCodes): array
    {
        if (!$enabled) {
            return [
                'status' => 'disabled',
                'status_text' => '未启用',
                'summary_value' => '关闭',
                'tone' => 'gray',
                'message' => '系统配置未开启网页流水监听。',
            ];
        }

        if ($configuredCount <= 0) {
            return [
                'status' => 'empty_config',
                'status_text' => '未配置插件',
                'summary_value' => '未配置',
                'tone' => 'warning',
                'message' => '网页流水监听已开启，但系统配置中没有填写支持插件标识。',
            ];
        }

        if ($liveInstances <= 0) {
            return [
                'status' => 'offline',
                'status_text' => '监听工具未上报',
                'summary_value' => '离线',
                'tone' => 'warning',
                'message' => 'Webman 已启用网页流水监听，但 Python receipt_watcher 尚未上报能力心跳。',
            ];
        }

        if ($missingCodes !== []) {
            return [
                'status' => 'missing_plugin',
                'status_text' => '部分插件未支持',
                'summary_value' => '缺失 ' . count($missingCodes),
                'tone' => 'warning',
                'message' => '后台配置的插件未在 Python receipt_watcher 中上报支持：' . implode('、', $missingCodes),
            ];
        }

        return [
            'status' => 'running',
            'status_text' => '能力正常',
            'summary_value' => '正常',
            'tone' => 'success',
            'message' => 'Python receipt_watcher 已上报网页流水监听能力。',
        ];
    }

    /**
     * 插件行状态。
     *
     * @param bool $enabled 总开关是否启用
     * @param bool $configured 插件是否配置
     * @param bool $supported watcher 是否支持
     * @return array<string, string>
     */
    private function pluginStatus(bool $enabled, bool $configured, bool $supported): array
    {
        if (!$enabled) {
            return ['status' => 'disabled', 'status_text' => '总开关关闭', 'tone' => 'gray'];
        }
        if ($configured && $supported) {
            return ['status' => 'ok', 'status_text' => '可用', 'tone' => 'success'];
        }
        if ($configured) {
            return ['status' => 'missing', 'status_text' => '监听工具未支持', 'tone' => 'warning'];
        }

        return ['status' => 'extra', 'status_text' => '未启用', 'tone' => 'gray'];
    }

    /**
     * 获取后台配置的网页监听插件编码。
     *
     * @return array<int, string>
     */
    private function configuredPluginCodes(): array
    {
        $raw = (string) sys_config('receipt_watcher_plugin_codes', '');
        $parts = preg_split('/[\s,，;；]+/', $raw) ?: [];
        $codes = [];
        foreach ($parts as $part) {
            $code = trim((string) $part);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * 判断网页流水监听总开关是否开启。
     *
     * @return bool 是否启用
     */
    private function watcherEnabled(): bool
    {
        $value = strtolower(trim((string) sys_config('receipt_watcher_enabled', '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * 能力标识转展示文案。
     *
     * @param array<int, string> $features 能力标识
     * @return string 展示文案
     */
    private function featuresText(array $features): string
    {
        $map = [
            'api_request' => '接口查询',
            'browser' => '浏览器',
            'captcha' => '验证码',
        ];
        $texts = [];
        foreach (array_values(array_unique($features)) as $feature) {
            $texts[] = $map[$feature] ?? $feature;
        }

        return $texts === [] ? '—' : implode('、', $texts);
    }

    /**
     * 时间戳展示文本。
     *
     * @param int $timestamp 时间戳
     * @return string 展示文本
     */
    private function timestampText(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '—';
    }

    /**
     * 秒数转中文时长。
     *
     * @param int $seconds 秒数
     * @return string 时长文本
     */
    private function durationText(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . ' 秒';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' 分钟';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . ' 小时 ' . floor(($seconds % 3600) / 60) . ' 分钟';
        }

        return floor($seconds / 86400) . ' 天 ' . floor(($seconds % 86400) / 3600) . ' 小时';
    }

    /**
     * Redis Key 安全片段。
     *
     * @param string $value 原始值
     * @return string 安全片段
     */
    private function safeKeyPart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $value) ?? '';

        return trim($safe, '_') !== '' ? trim($safe, '_') : 'empty';
    }
}
