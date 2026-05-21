<?php

namespace app\bootstrap;

use app\service\system\config\SystemConfigRuntimeService;
use support\Container;
use support\Log;
use Webman\Bootstrap;
use Workerman\Worker;

/**
 * 系统配置启动预热。
 *
 * 安装完成后 .env 只有在 Webman 重启后才会生效，因此系统配置缓存预热放在启动阶段执行。
 */
class SystemConfigBootstrap implements Bootstrap
{
    /**
     * 启动时刷新一次系统配置运行时缓存。
     *
     * @param Worker|null $worker Worker 实例
     * @return void
     */
    public static function start(?Worker $worker): void
    {
        if (
            $worker === null
            || $worker->name !== 'webman'
            || $worker->id !== 0
            || !is_file(base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'install.lock')
        ) {
            return;
        }

        $runtimeDir = runtime_path('bootstrap');
        if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
            return;
        }

        $pidFile = runtime_path('webman.pid');
        $masterPid = is_file($pidFile) ? trim((string) @file_get_contents($pidFile)) : 'unknown';
        $markerFile = $runtimeDir . DIRECTORY_SEPARATOR . 'system_config_bootstrap.pid';
        $lockFile = $runtimeDir . DIRECTORY_SEPARATOR . 'system_config_bootstrap.lock';
        $lock = @fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return;
        }

        try {
            $bootstrappedPid = is_file($markerFile) ? trim((string) @file_get_contents($markerFile)) : '';
            if ($bootstrappedPid === $masterPid) {
                return;
            }

            /** @var SystemConfigRuntimeService $service */
            $service = Container::get(SystemConfigRuntimeService::class);
            $service->refresh();
            @file_put_contents($markerFile, $masterPid, LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('[SystemConfigBootstrap] 系统配置启动预热失败: ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
