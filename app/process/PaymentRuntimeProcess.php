<?php

namespace app\process;

use app\service\payment\runtime\PaymentRuntimeMaintenanceService;
use app\service\system\config\SystemConfigRuntimeService;
use app\service\system\ops\SystemOpsHeartbeatService;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 支付运行时维护进程。
 *
 * 使用 Webman 自定义进程承载轻量定时任务，避免把通知重试和主动查单塞进请求链路。
 */
class PaymentRuntimeProcess
{
    /**
     * 任务上次执行时间。
     *
     * @var array<string, int>
     */
    private array $lastRunAt = [];

    /**
     * 任务运行锁。
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
     * Worker 启动时注册心跳定时器。
     *
     * @param Worker $worker Worker 实例
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        $heartbeat = $this->intOption('heartbeat_seconds', 5, 1, 60);
        // 启动时先写一次心跳，避免监控页在首次 Timer tick 前显示“未上报”。
        $this->reportHeartbeat([
            'summary' => '支付运行时维护进程已启动',
            'heartbeat_seconds' => $heartbeat,
        ]);

        Timer::add($heartbeat, function (): void {
            $this->tick();
        });

        Log::info(sprintf('[PaymentRuntimeProcess] 支付运行时维护进程已启动 heartbeat=%s', $heartbeat));
    }

    /**
     * 心跳调度入口。
     *
     * @return void
     */
    private function tick(): void
    {
        try {
            if (!$this->boolConfig('pay_runtime_enabled', true)) {
                $this->reportHeartbeat([
                    'summary' => '支付运行时维护已停用',
                    'enabled' => false,
                ]);
                return;
            }

            $this->reportHeartbeat([
                'summary' => '支付运行时维护调度中',
                'enabled' => true,
            ]);

            $this->runIfDue(
                'notify_retry',
                $this->intConfig('pay_notify_retry_scan_interval_seconds', 60, 5),
                fn (): array => $this->maintenanceService()->retryMerchantNotifies(
                    $this->intConfig('pay_notify_retry_batch_size', 100, 1)
                )
            );

            if ($this->boolConfig('pay_order_timeout_enabled', true)) {
                $this->runIfDue(
                    'order_timeout',
                    $this->intConfig('pay_order_timeout_scan_interval_seconds', 60, 5),
                    fn (): array => $this->maintenanceService()->timeoutExpiredPayOrders(
                        $this->intConfig('pay_order_timeout_batch_size', 100, 1)
                    )
                );
            }

            if ($this->boolConfig('pay_active_query_enabled', true)) {
                $this->runIfDue(
                    'active_query',
                    $this->intConfig('pay_active_query_interval_seconds', 60, 10),
                    fn (): array => $this->maintenanceService()->syncPayingOrdersByQuery(
                        $this->intConfig('pay_active_query_batch_size', 50, 1),
                        $this->intConfig('pay_active_query_min_age_seconds', 60, 1)
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->reportHeartbeat([
                'summary' => '支付运行时维护调度异常',
                'last_error' => $e->getMessage(),
            ]);
            Log::warning('[PaymentRuntimeProcess] 心跳调度失败：' . $e->getMessage());
        }
    }

    /**
     * 到期后执行任务，并避免同类任务重叠运行。
     *
     * @param string $key 任务键
     * @param int $intervalSeconds 执行间隔
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
            if ($this->hasWork($summary)) {
                Log::info(sprintf(
                    '[PaymentRuntimeProcess] %s 执行完成 %s',
                    $key,
                    json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ));
            }
        } catch (\Throwable $e) {
            $this->reportHeartbeat([
                'summary' => $key . ' 执行失败',
                'current_task' => $key,
                'last_error' => $e->getMessage(),
            ]);
            Log::warning(sprintf('[PaymentRuntimeProcess] %s 执行失败：%s', $key, $e->getMessage()));
        } finally {
            $this->running[$key] = false;
        }
    }

    /**
     * 判断任务摘要里是否有实际工作量。
     *
     * @param array<string, int> $summary 任务摘要
     * @return bool 是否有工作量
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
     * 获取维护服务。
     *
     * @return PaymentRuntimeMaintenanceService 维护服务
     */
    private function maintenanceService(): PaymentRuntimeMaintenanceService
    {
        return container_get(PaymentRuntimeMaintenanceService::class);
    }

    /**
     * 读取布尔系统配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 配置值
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = strtolower(trim($this->runtimeConfig()->get($key, $default ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * 读取整数系统配置。
     *
     * @param string $key 配置键
     * @param int $default 默认值
     * @param int $min 最小值
     * @return int 配置值
     */
    private function intConfig(string $key, int $default, int $min = 1): int
    {
        $value = (int) $this->runtimeConfig()->get($key, $default);

        return max($min, $value);
    }

    /**
     * 读取整数构造选项。
     *
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
     * 获取系统配置运行时服务。
     *
     * @return SystemConfigRuntimeService 运行时配置服务
     */
    private function runtimeConfig(): SystemConfigRuntimeService
    {
        return container_get(SystemConfigRuntimeService::class);
    }

    /**
     * 上报运维心跳。
     *
     * 心跳只服务管理后台运行监控，失败不能影响通知重试、订单超时和主动查单。
     *
     * @param array<string, mixed> $payload 心跳内容
     * @return void
     */
    private function reportHeartbeat(array $payload): void
    {
        try {
            /** @var SystemOpsHeartbeatService $service */
            $service = container_get(SystemOpsHeartbeatService::class);
            $service->report('payment-runtime', $payload);
        } catch (\Throwable) {
            // 监控写入失败时静默降级，业务维护任务继续运行。
        }
    }
}
