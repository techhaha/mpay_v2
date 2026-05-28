<?php

namespace app\queue\job;

use app\exception\PaymentException;
use app\queue\support\AbstractQueueJob;
use app\service\payment\order\PayOrderCallbackService;
use app\service\payment\receipt\ReceiptWatcherService;
use RuntimeException;
use support\Log;

/**
 * 网页流水监听通知任务。
 *
 * 每条消息建议只包含一条归一化流水，便于幂等、重试和日志定位。
 */
class ReceiptFlowNotifyJob extends AbstractQueueJob
{
    /**
     * 构造方法。
     *
     * @param ReceiptWatcherService $receiptWatcherService 网页流水监听服务
     * @param PayOrderCallbackService $payOrderCallbackService 支付单回调服务
     * @return void
     */
    public function __construct(
        protected ReceiptWatcherService $receiptWatcherService,
        protected PayOrderCallbackService $payOrderCallbackService
    ) {
    }

    /**
     * 处理网页流水监听消息。
     *
     * @param array<string, mixed> $data 队列消息
     * @return void
     */
    public function handle(array $data): void
    {
        $pluginCode = $this->requireString($data, 'plugin_code', '插件编码');
        $apiConfigId = (int) ($data['api_config_id'] ?? 0);
        if ($apiConfigId <= 0) {
            throw new RuntimeException('插件配置ID不能为空');
        }

        $records = $this->records($data);
        foreach ($records as $record) {
            $this->handleRecord($pluginCode, $apiConfigId, $data, $record);
        }
    }

    /**
     * 处理单条流水。
     *
     * @param string $pluginCode 插件编码
     * @param int $apiConfigId 插件配置ID
     * @param array<string, mixed> $data 原始队列消息
     * @param array<string, mixed> $record 流水记录
     * @return void
     */
    private function handleRecord(string $pluginCode, int $apiConfigId, array $data, array $record): void
    {
        if ($this->receiptWatcherService->isFlowSeen($pluginCode, $apiConfigId, $record)) {
            return;
        }

        $token = $this->receiptWatcherService->acquireFlowLock($pluginCode, $apiConfigId, $record);
        if ($token === null) {
            return;
        }

        $startedAt = microtime(true);
        $orderNo = trim((string) ($record['order_no'] ?? ''));
        $pushedAt = (int) ($data['pushed_at'] ?? 0);
        $firstPushedAt = (int) ($data['first_pushed_at'] ?? $pushedAt);
        $pushAttempt = (int) ($data['push_attempt'] ?? 1);
        $queryStartedAt = (int) ($data['query_started_at'] ?? 0);
        $queryFinishedAt = (int) ($data['query_finished_at'] ?? 0);
        $queryDurationMs = (int) ($data['query_duration_ms'] ?? 0);
        try {
            $payType = trim((string) ($record['pay_type'] ?? ''));
            $channel = $this->receiptWatcherService->resolveChannelForFlow($pluginCode, $apiConfigId, $payType);
            if (!$channel) {
                throw new PaymentException('流水未匹配到可用支付通道', 40200, [
                    'plugin_code' => $pluginCode,
                    'api_config_id' => $apiConfigId,
                    'pay_type' => $payType,
                ]);
            }

            $payload = $data;
            $payload['record'] = $record;
            $payload['channel_id'] = (int) $channel->id;
            $callbackPayload = $this->payOrderCallbackService->handleChannelNotifyPayload((int) $channel->id, $payload);
            if (empty($callbackPayload['success'])) {
                throw new RuntimeException('流水通知未确认支付成功');
            }
            $this->receiptWatcherService->markFlowSeen($pluginCode, $apiConfigId, $record);
            Log::info(sprintf(
                '[ReceiptFlowNotifyQueue] 消费成功 plugin=%s api_config_id=%s order_no=%s pay_type=%s price=%s pay_no=%s query_duration=%sms query_to_push=%ss query_finish_to_push=%ss first_pushed_lag=%ss pushed_lag=%ss push_attempt=%s duration=%sms',
                $pluginCode,
                $apiConfigId,
                $orderNo,
                $payType,
                (string) ($record['price'] ?? ''),
                (string) ($callbackPayload['pay_no'] ?? ''),
                $queryDurationMs > 0 ? (string) $queryDurationMs : '-',
                ($queryStartedAt > 0 && $pushedAt > 0) ? (string) max(0, $pushedAt - $queryStartedAt) : '-',
                ($queryFinishedAt > 0 && $pushedAt > 0) ? (string) max(0, $pushedAt - $queryFinishedAt) : '-',
                $firstPushedAt > 0 ? (string) max(0, time() - $firstPushedAt) : '-',
                $pushedAt > 0 ? (string) max(0, time() - $pushedAt) : '-',
                $pushAttempt > 0 ? (string) $pushAttempt : '-',
                (string) (int) ((microtime(true) - $startedAt) * 1000)
            ));
        } finally {
            $this->receiptWatcherService->releaseFlowLock($pluginCode, $apiConfigId, $record, $token);
        }
    }

    /**
     * 读取消息中的流水列表。
     *
     * @param array<string, mixed> $data 队列消息
     * @return array<int, array<string, mixed>> 流水列表
     */
    private function records(array $data): array
    {
        if (isset($data['record']) && is_array($data['record'])) {
            return [$data['record']];
        }

        if (!isset($data['records']) || !is_array($data['records'])) {
            throw new RuntimeException('流水记录不能为空');
        }

        $records = [];
        foreach ($data['records'] as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        if ($records === []) {
            throw new RuntimeException('流水记录不能为空');
        }

        return $records;
    }

    /**
     * 获取日志名称。
     *
     * @return string 日志名称
     */
    protected function logName(): string
    {
        return 'ReceiptFlowNotifyQueue';
    }
}
