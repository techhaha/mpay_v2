<?php

namespace app\queue\support;

use app\common\interface\QueueJobInterface;
use RuntimeException;
use support\Log;
use Throwable;

/**
 * 队列任务基类。
 *
 * 提供消息字段校验、布尔值解析和最终失败日志，具体业务 Job 只需要实现 handle。
 */
abstract class AbstractQueueJob implements QueueJobInterface
{
    /**
     * 记录重试耗尽后的最终失败。
     *
     * 中间重试异常由 Redis Queue 框架记录，本方法只由 AbstractRedisConsumer
     * 在消息即将进入失败队列时调用。
     *
     * @param Throwable $exception 最后一次消费异常
     * @param array<string, mixed> $package 已包含最终执行次数的队列包
     * @return void
     */
    public function failed(Throwable $exception, array $package): void
    {
        Log::error(sprintf(
            '[%s] 队列任务最终失败 queue=%s attempts=%s error=%s',
            $this->logName(),
            (string) ($package['queue'] ?? ''),
            (string) ($package['attempts'] ?? ''),
            $exception->getMessage()
        ));
    }

    /**
     * 读取必填字符串字段。
     *
     * @param array<string, mixed> $data 队列消息
     * @param string $key 字段名
     * @param string $label 字段显示名
     * @return string 字段值
     */
    protected function requireString(array $data, string $key, string $label = ''): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException(($label !== '' ? $label : $key) . ' 不能为空');
        }

        return $value;
    }

    /**
     * 解析布尔字段。
     *
     * @param mixed $value 字段值
     * @return bool 布尔结果
     */
    protected function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return static::class;
    }
}
