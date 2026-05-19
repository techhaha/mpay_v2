<?php

namespace app\process;

use app\service\payment\receipt\ReceiptWatcherService;
use app\service\system\ops\SystemOpsHeartbeatService;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 网页流水监听调度进程。
 *
 * 该进程不访问第三方平台，只负责把当前需要查询流水的账号和订单同步到 Redis。
 */
class ReceiptWatcherProcess
{
    /**
     * 上次执行时间。
     *
     * @var array<string, int>
     */
    private array $lastRunAt = [];

    /**
     * 运行锁。
     *
     * @var array<string, bool>
     */
    private array $running = [];

    /**
     * 上次日志摘要。
     *
     * @var array<string, string>
     */
    private array $lastSummary = [];

    /**
     * 上次摘要日志时间。
     *
     * @var array<string, int>
     */
    private array $lastSummaryLoggedAt = [];

    /**
     * 构造方法。
     *
     * @param array<string, mixed> $options 进程选项
     */
    public function __construct(
        private array $options = []
    ) {
    }

    /**
     * Worker 启动。
     *
     * @param Worker $worker Worker 实例
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        try {
            $this->watcherService()->refreshChannelCache();
        } catch (\Throwable $e) {
            Log::warning('[ReceiptWatcherProcess] 启动刷新账号缓存失败：' . $e->getMessage());
        }

        $heartbeat = $this->intOption('heartbeat_seconds', 1, 1, 60);
        // 启动时先写一次心跳，避免监控页在首次 Timer tick 前显示“未上报”。
        $this->reportHeartbeat([
            'summary' => '网页流水监听调度进程已启动',
            'heartbeat_seconds' => $heartbeat,
        ]);
        Timer::add($heartbeat, function (): void {
            $this->tick();
        });

        Log::info(sprintf('[ReceiptWatcherProcess] 网页流水监听调度进程已启动 heartbeat=%s', $heartbeat));
    }

    /**
     * 心跳调度入口。
     *
     * @return void
     */
    private function tick(): void
    {
        try {
            $this->reportHeartbeat([
                'summary' => '网页流水监听调度中',
            ]);

            $this->runIfDue('refresh_channels', 60, function (): array {
                return $this->watcherService()->refreshChannelCache();
            });

            $this->runIfDue('sync_pending_orders', $this->scanIntervalSeconds(), function (): array {
                return $this->watcherService()->syncPendingOrders($this->scanBatchSize());
            });
        } catch (\Throwable $e) {
            $this->reportHeartbeat([
                'summary' => '网页流水监听调度异常',
                'last_error' => $e->getMessage(),
            ]);
            Log::warning('[ReceiptWatcherProcess] 心跳调度失败：' . $e->getMessage());
        }
    }

    /**
     * 到期后执行任务。
     *
     * @param string $key 任务键
     * @param int $intervalSeconds 间隔秒数
     * @param callable $callback 任务回调
     * @return void
     */
    private function runIfDue(string $key, int $intervalSeconds, callable $callback): void
    {
        $now = time();
        $lastRunAt = (int) ($this->lastRunAt[$key] ?? 0);
        if ($lastRunAt > 0 && $now - $lastRunAt < $intervalSeconds) {
            return;
        }
        if (!empty($this->running[$key])) {
            return;
        }

        $this->lastRunAt[$key] = $now;
        $this->running[$key] = true;

        try {
            $summary = $callback();
            $this->reportHeartbeat([
                'summary' => $key . ' 执行完成',
                'current_task' => $key,
                'task_summary' => $summary,
            ]);
            if ($this->shouldLogSummary($key, $summary)) {
                Log::info(sprintf(
                    '[ReceiptWatcherProcess] %s 执行完成 %s',
                    $key,
                    $this->summaryText($summary)
                ));
            }
        } catch (\Throwable $e) {
            $this->reportHeartbeat([
                'summary' => $key . ' 执行失败',
                'current_task' => $key,
                'last_error' => $e->getMessage(),
            ]);
            Log::warning(sprintf('[ReceiptWatcherProcess] %s 执行失败：%s', $key, $e->getMessage()));
        } finally {
            $this->running[$key] = false;
        }
    }

    /**
     * @param array<string, int> $summary 任务摘要
     * @return bool 是否有实际工作量
     */
    private function hasWork(array $summary): bool
    {
        foreach ($summary as $value) {
            if ((int) $value > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断是否需要记录执行摘要。
     *
     * 有实际工作量时立即记录；没有工作量时按摘要变化或固定间隔记录，方便排查空转原因。
     *
     * @param string $key 任务键
     * @param array<string, int> $summary 任务摘要
     * @return bool 是否记录日志
     */
    private function shouldLogSummary(string $key, array $summary): bool
    {
        $text = $this->summaryText($summary);
        $now = time();
        $changed = ($this->lastSummary[$key] ?? '') !== $text;
        $elapsed = $now - (int) ($this->lastSummaryLoggedAt[$key] ?? 0);

        if ($this->hasWork($summary) || $changed || $elapsed >= 60) {
            $this->lastSummary[$key] = $text;
            $this->lastSummaryLoggedAt[$key] = $now;
            return true;
        }

        return false;
    }

    /**
     * @param array<string, int> $summary 任务摘要
     * @return string JSON 文本
     */
    private function summaryText(array $summary): string
    {
        return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return int 待支付订单扫描间隔
     */
    private function scanIntervalSeconds(): int
    {
        return max(2, (int) sys_config('receipt_watcher_order_scan_interval_seconds', 3));
    }

    /**
     * @return int 待支付订单扫描批量
     */
    private function scanBatchSize(): int
    {
        return max(1, (int) sys_config('receipt_watcher_order_scan_batch_size', 500));
    }

    /**
     * @param string $key 配置键
     * @param int $default 默认值
     * @param int $min 最小值
     * @param int $max 最大值
     * @return int 配置值
     */
    private function intOption(string $key, int $default, int $min, int $max): int
    {
        $value = (int) ($this->options[$key] ?? $default);

        return min($max, max($min, $value));
    }

    /**
     * @return ReceiptWatcherService 网页流水监听服务
     */
    private function watcherService(): ReceiptWatcherService
    {
        return container_get(ReceiptWatcherService::class);
    }

    /**
     * 上报运维心跳。
     *
     * 心跳只服务管理后台运行监控，失败不能影响网页流水监听任务同步。
     *
     * @param array<string, mixed> $payload 心跳内容
     * @return void
     */
    private function reportHeartbeat(array $payload): void
    {
        try {
            /** @var SystemOpsHeartbeatService $service */
            $service = container_get(SystemOpsHeartbeatService::class);
            $service->report('receipt-watcher', $payload);
        } catch (\Throwable) {
            // 监控写入失败时静默降级，监听调度继续运行。
        }
    }
}
