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
     * 处理消费失败。
     *
     * @param Throwable $exception 异常
     * @param array<string, mixed> $package 原始队列包
     * @return void
     */
    public function onConsumeFailure(Throwable $exception, array $package): void
    {
        try {
            $this->job()->failed($exception, $package);
        } catch (Throwable $failureException) {
            Log::warning(sprintf(
                '[QueueConsumer] 失败处理异常 job=%s queue=%s error=%s failure_error=%s',
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
