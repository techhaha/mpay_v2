<?php

namespace app\queue\support;

use app\common\interface\QueueJobInterface;
use RuntimeException;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * Redis 队列消费者基类。
 *
 * 统一把 webman/redis-queue 的 Consumer 协议适配到业务 Job，具体消费者只需要声明
 * 队列名和 Job 类名。
 */
abstract class AbstractRedisConsumer implements Consumer
{
    /**
     * Redis 队列连接名。
     *
     * @var string
     */
    public $connection = 'default';

    /**
     * 获取任务类名。
     *
     * @return class-string<QueueJobInterface> 任务类名
     */
    abstract protected function jobClass(): string;

    /**
     * 消费队列消息。
     *
     * @param mixed $data 队列消息
     * @return void
     */
    public function consume($data): void
    {
        $this->job()->handle(is_array($data) ? $data : []);
    }

    /**
     * 把重试耗尽的消费异常交给业务 Job 处理。
     *
     * Redis Queue 会在每次消费异常后调用本方法，并在回调返回后增加 attempts。
     * 中间失败已经由框架记录；这里只在下一次计数超过 max_attempts 时触发
     * Job 的最终失败处理，避免每次重试重复写相同的业务日志。
     *
     * @param Throwable $exception 本次消费异常
     * @param array<string, mixed> $package 框架尚未增加本次 attempts 的队列包
     * @return void
     */
    public function onConsumeFailure(Throwable $exception, array $package): void
    {
        $currentAttempt = (int) ($package['attempts'] ?? 0) + 1;
        $maxAttempts = (int) ($package['max_attempts'] ?? 0);
        if ($currentAttempt <= $maxAttempts) {
            return;
        }

        $package['attempts'] = $currentAttempt;
        try {
            $this->job()->failed($exception, $package);
        } catch (Throwable $failureException) {
            Log::error(sprintf(
                '[QueueConsumer] 最终失败处理异常 job=%s queue=%s error=%s failure_error=%s',
                $this->jobClass(),
                (string) ($package['queue'] ?? ''),
                $exception->getMessage(),
                $failureException->getMessage()
            ));
        }
    }

    /**
     * 从容器中获取任务实例。
     *
     * Job 不保存单次消费的可变状态，使用 container_get 复用实例，避免每条消息重复构造依赖。
     *
     * @return QueueJobInterface 任务实例
     */
    private function job(): QueueJobInterface
    {
        $job = container_get($this->jobClass());
        if (!$job instanceof QueueJobInterface) {
            throw new RuntimeException('队列任务必须实现 QueueJobInterface：' . $this->jobClass());
        }

        return $job;
    }
}
