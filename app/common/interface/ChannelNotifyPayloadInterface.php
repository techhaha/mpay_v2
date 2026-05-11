<?php
declare(strict_types=1);

namespace app\common\interface;

/**
 * 非 HTTP 通道级数组载荷通知接口。
 *
 * 适用于 receipt_watcher 这类队列入口：外部监听工具已经把平台流水归一化为数组，
 * 并投递到 Redis 队列。服务层先调用 channelNotifyPayload() 定位 pay_no，再调用
 * notifyPayload() 获取标准插件通知结果，后续复用订单状态推进、回调日志和商户通知链路。
 *
 * 该接口不接收 Request，也不用于 /api/pay/{chanId}/notify 这类 HTTP 入口。
 */
interface ChannelNotifyPayloadInterface
{
    /**
     * 根据数组载荷定位支付单。
     *
     * @param array<string, mixed> $payload 已归一化的通道通知载荷
     * @return array{pay_no:string} 定位结果
     */
    public function channelNotifyPayload(array $payload): array;

    /**
     * 解析数组载荷并返回标准插件通知结果。
     *
     * @param array<string, mixed> $payload 已归一化的通道通知载荷
     * @return array<string, mixed> 插件回调结果
     */
    public function notifyPayload(array $payload): array;
}
