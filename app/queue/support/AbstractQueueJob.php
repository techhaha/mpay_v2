<?php

namespace app\queue\support;

use app\common\interface\QueueJobInterface;
use RuntimeException;
use support\Log;
use Throwable;

/**
 * 队列任务基类。
 *
 * 提供消息字段校验、布尔值解析和统一失败日志，具体业务 Job 只需要实现 handle。
 */
abstract class AbstractQueueJob implements QueueJobInterface
{
    /**
     * 默认消费失败处理。
     *
     * @param Throwable $exception 异常
     * @param array<string, mixed> $package 原始队列包
     * @return void
     */
    public function failed(Throwable $exception, array $package): void
    {
        Log::warning(sprintf(
            '[%s] 消费失败 queue=%s attempts=%s error=%s',
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
