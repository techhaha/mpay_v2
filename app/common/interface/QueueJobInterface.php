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
     * 处理消费失败。
     *
     * @param Throwable $exception 异常
     * @param array<string, mixed> $package 原始队列包
     * @return void
     */
    public function failed(Throwable $exception, array $package): void;
}
