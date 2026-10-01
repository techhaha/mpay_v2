<?php

namespace app\common\interface;

use Throwable;

/**
 * 队列任务接口。
 *
 * Consumer 只关心消息消费协议，具体业务处理统一交给 Job，便于后续按任务维度管理、
 * 测试、统计和扩展失败处理。
 */
interface QueueJobInterface
{
    /**
     * 处理队列消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void;

    /**
     * 处理重试耗尽后的最终失败。
     *
     * 中间重试由 Redis Queue 框架负责记录和调度，只有消息即将进入失败队列时
     * 才调用本方法，供 Job 记录最终错误或执行任务专属的失败处理。
     *
     * @param Throwable $exception 最后一次消费异常
     * @param array<string, mixed> $package 已包含最终执行次数的队列包
     * @return void
     */
    public function failed(Throwable $exception, array $package): void;
}
