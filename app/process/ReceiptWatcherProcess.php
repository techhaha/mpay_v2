<?php

namespace app\process;

use app\service\payment\receipt\ReceiptWatcherService;
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
            $this->runIfDue('refresh_channels', 60, function (): array {
                return $this->watcherService()->refreshChannelCache();
            });

            $this->runIfDue('sync_pending_orders', $this->scanIntervalSeconds(), function (): array {
                return $this->watcherService()->syncPendingOrders($this->scanBatchSize());
            });
        } catch (\Throwable $e) {
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
            if ($this->hasWork($summary)) {
                Log::info(sprintf(
                    '[ReceiptWatcherProcess] %s 执行完成 %s',
                    $key,
                    json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ));
            }
        } catch (\Throwable $e) {
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
}
