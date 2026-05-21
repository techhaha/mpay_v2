<?php

namespace app\service\system\ops;

use app\common\base\BaseService;
use app\service\payment\receipt\ReceiptWatcherRuntimeStatusService;
use Composer\InstalledVersions;
use support\Db;
use support\Redis;
use Throwable;

/**
 * Webman 运行监控聚合服务。
 *
 * 只做只读状态聚合，不在这里触发 reload/restart 等高风险动作。
 * 数据来源包含 Webman 配置、插件进程配置、runtime 文件、进程心跳、基础依赖探测和日志尾部摘要。
 */
class SystemOpsStatusService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param SystemOpsHeartbeatService $heartbeatService 系统运维心跳服务
     * @param SystemOpsOperationLogService $operationLogService 系统运维操作日志服务
     * @return void
     */
    public function __construct(
        protected SystemOpsHeartbeatService $heartbeatService,
        protected SystemOpsOperationLogService $operationLogService,
        protected ReceiptWatcherRuntimeStatusService $receiptWatcherRuntimeStatusService
    ) {
    }

    /**
     * 获取运行监控总览。
     *
     * 这个接口会被后台首页频繁刷新，内部避免执行 shell 状态命令，
     * 尽量通过轻量文件和短超时连接检查完成。
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $server = (array) config('server', []);
        $processConfig = $this->effectiveProcessConfig($server);
        $pidFile = (string) ($server['pid_file'] ?? runtime_path('webman.pid'));
        $masterPid = $this->readPid($pidFile);
        $masterAlive = $masterPid > 0 && $this->isProcessAlive($masterPid);
        $processes = $this->processes($processConfig, $masterPid, $masterAlive);
        $logs = $this->logSummary($server);
        $dependencies = $this->dependencies();
        $receiptWatcherRuntime = $this->receiptWatcherRuntimeStatusService->overview();

        return [
            'generated_at' => $this->formatDateTime($this->now()),
            'runtime' => $this->runtimeStatus($server, $pidFile, $masterPid, $masterAlive),
            'application' => $this->applicationInfo(),
            'summary' => $this->summary($processes, $logs, $dependencies, $receiptWatcherRuntime),
            'resources' => $this->resources($server),
            'dependencies' => $dependencies,
            'receipt_watcher_runtime' => $receiptWatcherRuntime,
            'processes' => $processes,
            'logs' => $logs,
            'operations' => $this->operationLogService->latest(8),
            'actions' => [
                'reload' => $this->canRunCommand(),
                'restart' => $this->canRunCommand(),
                'tips' => '仅允许执行白名单运维动作，重启会短暂影响请求处理。',
            ],
        ];
    }

    /**
     * 构建运行状态。
     *
     * 开发环境或 Windows 下可能没有稳定的 master PID 文件。
     * 只要当前请求能进入 Webman，就仍然按“运行中（无 PID 文件）”展示，避免误报停机。
     *
     * @param array<string, mixed> $server Webman server 配置
     * @param string $pidFile PID 文件
     * @param int $masterPid Master PID
     * @param bool $masterAlive Master 是否存活
     * @return array<string, mixed>
     */
    private function runtimeStatus(array $server, string $pidFile, int $masterPid, bool $masterAlive): array
    {
        $statusFile = (string) ($server['status_file'] ?? runtime_path('webman.status'));
        $status = $masterPid > 0 ? ($masterAlive ? 'running' : 'stale') : 'running';
        $statusText = match ($status) {
            'running' => '运行中',
            'stale' => 'PID 文件已失效',
            default => '未知',
        };
        $pidFileExists = is_file($pidFile);
        $statusFileExists = is_file($statusFile);
        $fileMissingText = DIRECTORY_SEPARATOR === '\\' ? 'Windows 模式未生成' : '未生成';

        $startedAt = $pidFileExists ? (int) filemtime($pidFile) : 0;

        return [
            'status' => $status,
            'status_text' => $statusText,
            'tone' => $status === 'running' ? 'success' : 'warning',
            'master_pid' => $masterPid,
            'current_worker_pid' => (int) getmypid(),
            'master_alive' => $masterAlive,
            'pid_file' => $pidFile,
            'pid_file_exists' => $pidFileExists,
            'pid_file_text' => $pidFileExists ? $pidFile : $fileMissingText,
            'status_file' => $statusFile,
            'status_file_exists' => $statusFileExists,
            'status_file_text' => $statusFileExists ? $statusFile : $fileMissingText,
            'start_time_text' => $startedAt > 0 ? date('Y-m-d H:i:s', $startedAt) : '—',
            'uptime_text' => $startedAt > 0 ? $this->durationText(time() - $startedAt) : '—',
        ];
    }

    /**
     * 读取不需要外部连接的基础应用信息。
     *
     * @return array<string, mixed>
     */
    private function applicationInfo(): array
    {
        return [
            'timezone' => (string) config('app.default_timezone', date_default_timezone_get()),
            'debug' => (bool) config('app.debug', false),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY . ' / ' . php_uname('s'),
            'webman_version' => $this->packageVersion('workerman/webman-framework'),
            'workerman_version' => $this->packageVersion('workerman/workerman'),
            'git_commit' => $this->gitCommit(),
            'base_path' => base_path(false),
            'runtime_path' => runtime_path(),
        ];
    }

    /**
     * 汇总卡片。
     *
     * 汇总只用于页面顶部快速判断，详细排查仍以进程、依赖和日志分区为准。
     *
     * @param array<int, array<string, mixed>> $processes 进程列表
     * @param array<string, mixed> $logs 日志摘要
     * @param array<int, array<string, mixed>> $dependencies 依赖状态
     * @param array<string, mixed> $receiptWatcherRuntime 网页监听工具运行状态
     * @return array<int, array<string, mixed>>
     */
    private function summary(array $processes, array $logs, array $dependencies, array $receiptWatcherRuntime): array
    {
        $healthy = 0;
        $warning = 0;
        foreach ($processes as $process) {
            if (($process['tone'] ?? '') === 'success') {
                $healthy++;
            } elseif (($process['tone'] ?? '') !== 'gray') {
                $warning++;
            }
        }

        $dependencyWarning = 0;
        foreach ($dependencies as $dependency) {
            if (($dependency['tone'] ?? '') !== 'success') {
                $dependencyWarning++;
            }
        }

        return [
            ['key' => 'runtime', 'label' => '服务状态', 'value' => $warning === 0 ? '正常' : '关注', 'tone' => $warning === 0 ? 'success' : 'warning'],
            ['key' => 'process', 'label' => '长驻进程', 'value' => $healthy . ' / ' . count($processes), 'tone' => $warning === 0 ? 'success' : 'warning'],
            ['key' => 'memory', 'label' => '当前内存', 'value' => $this->formatBytes(memory_get_usage(true)), 'tone' => 'primary'],
            ['key' => 'log', 'label' => '日志告警', 'value' => (string) ($logs['error_count'] ?? 0), 'tone' => ((int) ($logs['error_count'] ?? 0)) > 0 ? 'danger' : 'success'],
            ['key' => 'dependency', 'label' => '依赖异常', 'value' => (string) $dependencyWarning, 'tone' => $dependencyWarning > 0 ? 'danger' : 'success'],
            ['key' => 'receipt_watcher', 'label' => '网页监听', 'value' => (string) ($receiptWatcherRuntime['summary_value'] ?? '—'), 'tone' => (string) ($receiptWatcherRuntime['tone'] ?? 'gray')],
        ];
    }

    /**
     * 运行资源。
     *
     * 仅展示 PHP 当前进程和 runtime/log 目录可用性，不代表整台服务器完整资源监控。
     *
     * @param array<string, mixed> $server Webman server 配置
     * @return array<int, array<string, mixed>>
     */
    private function resources(array $server): array
    {
        $runtime = runtime_path();
        $logs = dirname((string) ($server['log_file'] ?? runtime_path('logs/workerman.log')));
        $disk = $this->diskUsage($runtime);

        return [
            ['label' => 'PHP 当前内存', 'value' => $this->formatBytes(memory_get_usage(true)), 'helper' => '峰值 ' . $this->formatBytes(memory_get_peak_usage(true)), 'tone' => 'primary'],
            ['label' => 'Runtime 目录', 'value' => is_writable($runtime) ? '可写' : '不可写', 'helper' => $runtime, 'tone' => is_writable($runtime) ? 'success' : 'danger'],
            ['label' => '日志目录', 'value' => is_writable($logs) ? '可写' : '不可写', 'helper' => $logs, 'tone' => is_writable($logs) ? 'success' : 'danger'],
            ['label' => '磁盘空间', 'value' => $disk['used_rate_text'], 'helper' => $disk['free_text'] . ' 可用 / ' . $disk['total_text'], 'tone' => $disk['used_rate'] >= 90 ? 'danger' : ($disk['used_rate'] >= 80 ? 'warning' : 'success')],
        ];
    }

    /**
     * 依赖状态。
     *
     * 数据库和 Redis 先做短超时 TCP 探测，再调用框架连接。
     * 这样依赖不可达时不会让监控页长时间卡住。
     *
     * @return array<int, array<string, mixed>>
     */
    private function dependencies(): array
    {
        return [
            $this->dependency('MySQL', function (): string {
                $this->ensureTcpReachable(
                    (string) config('database.connections.mysql.host', '127.0.0.1'),
                    (int) config('database.connections.mysql.port', 3306),
                    'MySQL'
                );
                Db::connection()->select('select 1 as ok');

                return '连接正常';
            }),
            $this->dependency('Redis', function (): string {
                $this->ensureTcpReachable(
                    (string) config('redis.default.host', '127.0.0.1'),
                    (int) config('redis.default.port', 6379),
                    'Redis'
                );
                $pong = Redis::connection()->ping();

                return is_string($pong) && $pong !== '' ? $pong : '连接正常';
            }),
            $this->dependency('配置文件', function (): string {
                $files = [config_path('app.php'), config_path('process.php'), config_path('server.php')];
                foreach ($files as $file) {
                    if (!is_file($file)) {
                        throw new \RuntimeException('缺少配置文件：' . $file);
                    }
                }

                return '基础配置存在';
            }),
        ];
    }

    /**
     * 包装单个依赖检查，统一输出页面需要的状态、耗时和错误信息。
     *
     * @param string $name 依赖名称
     * @param callable $callback 检测函数
     * @return array<string, mixed>
     */
    private function dependency(string $name, callable $callback): array
    {
        $startedAt = microtime(true);
        try {
            $message = (string) $callback();

            return [
                'name' => $name,
                'status' => 'ok',
                'status_text' => '正常',
                'message' => $message,
                'latency_text' => number_format((microtime(true) - $startedAt) * 1000, 1) . ' ms',
                'tone' => 'success',
            ];
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'status' => 'failed',
                'status_text' => '异常',
                'message' => $e->getMessage(),
                'latency_text' => number_format((microtime(true) - $startedAt) * 1000, 1) . ' ms',
                'tone' => 'danger',
            ];
        }
    }

    /**
     * 用短超时 TCP 连接提前判断服务端口是否可达。
     *
     * 这里不替代真实驱动连接，只是避免 MySQL/Redis 不可达时阻塞请求。
     *
     * @param string $host 主机地址
     * @param int $port 端口
     * @param string $name 依赖名称
     * @return void
     */
    private function ensureTcpReachable(string $host, int $port, string $name): void
    {
        $host = trim($host);
        if ($host === '' || $port <= 0 || str_contains($host, '/')) {
            return;
        }

        $target = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
        $errorNo = 0;
        $errorMessage = '';
        $socket = @fsockopen($target, $port, $errorNo, $errorMessage, 0.35);
        if (!$socket) {
            throw new \RuntimeException($name . ' TCP 不可达：' . ($errorMessage ?: ('端口 ' . $port . ' 未连接')));
        }

        fclose($socket);
    }

    /**
     * 进程列表。
     *
     * 进程行以 Webman 实际启动时的进程配置为准，包含插件进程。
     * 有心跳的业务进程按心跳判断；未接入心跳的插件进程只展示配置态和监听信息。
     *
     * @param array<string, mixed> $processConfig 进程配置
     * @param int $masterPid Master PID
     * @param bool $masterAlive Master 是否存活
     * @return array<int, array<string, mixed>>
     */
    private function processes(array $processConfig, int $masterPid, bool $masterAlive): array
    {
        $heartbeats = $this->heartbeatService->all();
        $rows = [];

        foreach ($processConfig as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            $heartbeat = $heartbeats[(string) $name] ?? null;
            $interval = (int) ($config['constructor']['options']['heartbeat_seconds'] ?? 5);
            $maxAge = max(10, $interval * 4);
            $lastTickAt = (int) ($heartbeat['last_tick_at'] ?? 0);
            $age = $lastTickAt > 0 ? time() - $lastTickAt : null;
            $isWebman = (string) $name === 'webman';
            $expectsHeartbeat = isset($config['constructor']['options']['heartbeat_seconds'])
                || in_array((string) $name, ['payment-runtime', 'receipt-watcher', 'monitor'], true);
            $tone = 'gray';
            $status = 'unknown';
            $statusText = '未接入心跳';

            if ($isWebman) {
                $status = $masterPid > 0 && !$masterAlive ? 'stale' : 'running';
                $statusText = $status === 'running' ? '运行中' : 'PID 失效';
                $tone = $status === 'running' ? 'success' : 'warning';
            } elseif ($lastTickAt > 0) {
                $status = $age !== null && $age <= $maxAge ? 'running' : 'timeout';
                $statusText = $status === 'running' ? '运行中' : '心跳超时';
                $tone = $status === 'running' ? 'success' : 'warning';
            } elseif ($expectsHeartbeat) {
                $status = 'unknown';
                $statusText = '未上报';
                $tone = 'warning';
            } else {
                $status = 'running';
                $statusText = '运行中';
                $tone = 'success';
            }

            $rows[] = [
                'name' => (string) $name,
                'handler' => $this->shortClass((string) ($config['handler'] ?? '')),
                'listen' => $this->displayListen((string) ($config['listen'] ?? '')),
                'count' => $this->effectiveProcessCount((int) ($config['count'] ?? 1)),
                'configured_count' => (int) ($config['count'] ?? 1),
                'reloadable' => !array_key_exists('reloadable', $config) || (bool) $config['reloadable'],
                'pid' => (int) ($heartbeat['pid'] ?? ($isWebman ? ($masterPid ?: getmypid()) : 0)),
                'status' => $status,
                'status_text' => $statusText,
                'tone' => $tone,
                'last_tick_at' => $lastTickAt,
                'last_tick_at_text' => $lastTickAt > 0 ? date('Y-m-d H:i:s', $lastTickAt) : '未接入',
                'heartbeat_age_text' => $age === null ? '未接入' : $this->durationText($age) . '前',
                'summary' => $this->heartbeatSummary($heartbeat),
            ];
        }

        return $rows;
    }

    /**
     * 构建实际启动进程配置。
     *
     * Webman Windows 启动脚本会同时加载 config/process.php 和插件 process 配置，
     * 这里按相同命名规则合并，避免页面少显示插件进程。
     *
     * @param array<string, mixed> $server Webman server 配置
     * @return array<string, array<string, mixed>>
     */
    private function effectiveProcessConfig(array $server): array
    {
        $rows = (array) config('process', []);
        if (!isset($rows['webman'])) {
            $rows = array_merge([
                'webman' => [
                    'handler' => 'app\\process\\Http',
                    'listen' => (string) ($server['listen'] ?? ''),
                    'count' => (int) ($server['count'] ?? 1),
                ],
            ], $rows);
        }

        foreach ((array) config('plugin', []) as $firm => $projects) {
            if (!is_array($projects)) {
                continue;
            }

            foreach ($projects as $projectName => $project) {
                if (!is_array($project) || $projectName === 'process') {
                    continue;
                }

                foreach ((array) ($project['process'] ?? []) as $processName => $processConfig) {
                    if (is_array($processConfig)) {
                        $rows['plugin.' . $firm . '.' . $projectName . '.' . $processName] = $processConfig;
                    }
                }
            }

            foreach ((array) ($projects['process'] ?? []) as $processName => $processConfig) {
                if (is_array($processConfig)) {
                    $rows['plugin.' . $firm . '.' . $processName] = $processConfig;
                }
            }
        }

        return array_filter($rows, static fn ($config): bool => is_array($config));
    }

    /**
     * 获取当前平台下实际显示的进程数。
     *
     * Windows 启动脚本按进程文件启动，Workerman 终端表中每个 worker 显示为 1。
     *
     * @param int $configuredCount 配置进程数
     * @return int 展示进程数
     */
    private function effectiveProcessCount(int $configuredCount): int
    {
        return DIRECTORY_SEPARATOR === '\\' ? 1 : max(1, $configuredCount);
    }

    /**
     * 按 Workerman 终端口径展示监听地址。
     *
     * @param string $listen 监听地址
     * @return string 展示文本
     */
    private function displayListen(string $listen): string
    {
        $listen = trim($listen);

        return $listen !== '' ? $listen : 'none';
    }

    /**
     * 日志摘要。
     *
     * 只读取每个日志文件尾部片段并扫描异常关键字，避免大日志文件拖慢监控页。
     *
     * @param array<string, mixed> $server Webman server 配置
     * @return array<string, mixed>
     */
    private function logSummary(array $server): array
    {
        $paths = array_values(array_unique(array_filter([
            (string) ($server['log_file'] ?? runtime_path('logs/workerman.log')),
            (string) ($server['stdout_file'] ?? runtime_path('logs/stdout.log')),
            runtime_path('logs' . DIRECTORY_SEPARATOR . 'webman-' . date('Y-m-d') . '.log'),
        ])));

        $items = [];
        $errorCount = 0;
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $content = $this->tail($path, 131072);
            $lines = preg_split('/\r?\n/', $content) ?: [];
            foreach ($lines as $line) {
                if (!preg_match('/(error|exception|fatal|warning|失败|异常)/i', $line)) {
                    continue;
                }
                $errorCount++;
                $items[] = [
                    'file' => basename($path),
                    'message' => trim($line),
                ];
            }
        }

        return [
            'error_count' => $errorCount,
            'recent_errors' => array_slice(array_reverse($items), 0, 8),
            'files' => array_map(function (string $path): array {
                return [
                    'name' => basename($path),
                    'path' => $path,
                    'exists' => is_file($path),
                    'size_text' => is_file($path) ? $this->formatBytes((int) filesize($path)) : '—',
                    'updated_at_text' => is_file($path) ? date('Y-m-d H:i:s', (int) filemtime($path)) : '—',
                ];
            }, $paths),
        ];
    }

    /**
     * 读取 Webman master PID。
     *
     * @param string $pidFile PID 文件
     * @return int PID
     */
    private function readPid(string $pidFile): int
    {
        if (!is_file($pidFile)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($pidFile));
    }

    /**
     * 判断进程是否存在。
     *
     * Linux 优先使用 posix_kill($pid, 0)，Windows 开发环境退回 tasklist 查询；
     * 这里不会发送终止信号。
     *
     * @param int $pid 进程 PID
     * @return bool 是否存活
     */
    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (DIRECTORY_SEPARATOR === '\\' && function_exists('shell_exec') && !in_array('shell_exec', $this->disabledFunctions(), true)) {
            $output = (string) shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL');

            return str_contains($output, '"' . $pid . '"') || preg_match('/(^|,|\s)' . preg_quote((string) $pid, '/') . '($|,|\s)/', $output) === 1;
        }

        return false;
    }

    /**
     * 判断当前 PHP 环境是否允许提交后台命令。
     *
     * @return bool 是否允许提交后台命令
     */
    private function canRunCommand(): bool
    {
        foreach (['proc_open', 'exec', 'shell_exec'] as $function) {
            if (function_exists($function) && !in_array($function, $this->disabledFunctions(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 读取 php.ini 禁用函数列表。
     *
     * @return array<int, string>
     */
    private function disabledFunctions(): array
    {
        return array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    }

    /**
     * 从心跳 payload 提取适合列表展示的一句话摘要。
     *
     * @param array<string, mixed>|null $heartbeat 心跳数据
     * @return string 摘要文本
     */
    private function heartbeatSummary(?array $heartbeat): string
    {
        if (!$heartbeat) {
            return '未接入心跳';
        }

        $payload = (array) ($heartbeat['payload'] ?? []);
        $summary = trim((string) ($payload['summary'] ?? $payload['current_task'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $lastError = trim((string) ($payload['last_error'] ?? ''));

        return $lastError !== '' ? $lastError : '心跳正常';
    }

    /**
     * 将完整处理器类名压缩为短类名，减少表格噪音。
     *
     * @param string $class 完整类名
     * @return string 短类名
     */
    private function shortClass(string $class): string
    {
        if ($class === '') {
            return '—';
        }

        $parts = explode('\\', $class);

        return (string) end($parts);
    }

    /**
     * 读取指定目录所在磁盘空间。
     *
     * @param string $path 目录路径
     * @return array<string, mixed>
     */
    private function diskUsage(string $path): array
    {
        $total = (int) @disk_total_space($path);
        $free = (int) @disk_free_space($path);
        $used = max(0, $total - $free);
        $rate = $total > 0 ? (int) floor($used * 100 / $total) : 0;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'used_rate' => $rate,
            'used_rate_text' => $rate . '%',
            'total_text' => $this->formatBytes($total),
            'free_text' => $this->formatBytes($free),
        ];
    }

    /**
     * 读取文件尾部内容。
     *
     * @param string $path 文件路径
     * @param int $bytes 最多读取字节数
     * @return string 文件尾部文本
     */
    private function tail(string $path, int $bytes): string
    {
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return '';
        }

        $offset = max(0, $size - $bytes);
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * 将秒数转为面向页面展示的中文时长。
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
     * 格式化字节数。
     *
     * @param int $bytes 字节数
     * @return string 格式化文本
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0, $bytes);
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }

    /**
     * 读取 Composer 包版本，失败时返回占位符。
     *
     * @param string $package Composer 包名
     * @return string 版本号
     */
    private function packageVersion(string $package): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return '—';
        }

        try {
            return InstalledVersions::getPrettyVersion($package) ?: '—';
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * 直接读取 .git/HEAD 获取短提交号，避免为了展示版本执行 shell。
     *
     * @return string 短提交号
     */
    private function gitCommit(): string
    {
        $head = base_path(false) . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'HEAD';
        if (!is_file($head)) {
            return '—';
        }

        $value = trim((string) file_get_contents($head));
        if (str_starts_with($value, 'ref:')) {
            $ref = trim(substr($value, 4));
            $refPath = base_path(false) . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
            if (is_file($refPath)) {
                $value = trim((string) file_get_contents($refPath));
            }
        }

        return $value !== '' ? substr($value, 0, 8) : '—';
    }
}
