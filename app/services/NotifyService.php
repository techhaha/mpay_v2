<?php

namespace app\services;

use app\common\base\BaseService;
use app\models\PaymentNotifyTask;
use app\repositories\{PaymentNotifyTaskRepository, PaymentOrderRepository};
use support\Log;

/**
 * 商户通知服务
 *
 * 负责向商户发送支付结果通知
 */
class NotifyService extends BaseService
{
    public function __construct(
        protected PaymentNotifyTaskRepository $notifyTaskRepository,
        protected PaymentOrderRepository $orderRepository,
    ) {
    }

    /**
     * 创建通知任务
     * notify_url 从订单 extra 中获取（下单时由请求传入）
     */
    public function createNotifyTask(string $orderId): void
    {
        $order = $this->orderRepository->findByOrderId($orderId);
        if (!$order) {
            return;
        }

        $existing = $this->notifyTaskRepository->findByOrderId($orderId);
        if ($existing) {
            return;
        }

        $notifyUrl = $order->extra['notify_url'] ?? '';
        if (empty($notifyUrl)) {
            Log::warning('订单缺少 notify_url，跳过创建通知任务', ['order_id' => $orderId]);
            return;
        }

        $this->notifyTaskRepository->create([
            'order_id' => $orderId,
            'merchant_id' => $order->merchant_id,
            'merchant_app_id' => $order->merchant_app_id,
            'notify_url' => $notifyUrl,
            'notify_data' => json_encode([
                'order_id' => $order->order_id,
                'mch_order_no' => $order->mch_order_no,
                'status' => $order->status,
                'amount' => $order->amount,
                'pay_time' => $order->pay_at,
            ], JSON_UNESCAPED_UNICODE),
            'status' => PaymentNotifyTask::STATUS_PENDING,
            'retry_cnt' => 0,
            'next_retry_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 发送通知
     */
    public function sendNotify(PaymentNotifyTask $task): bool
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $task->notify_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $task->notify_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $success = ($httpCode === 200 && strtolower(trim($response)) === 'success');

            $this->notifyTaskRepository->updateById($task->id, [
                'status' => $success ? PaymentNotifyTask::STATUS_SUCCESS : PaymentNotifyTask::STATUS_PENDING,
                'retry_cnt' => $task->retry_cnt + 1,
                'last_notify_at' => date('Y-m-d H:i:s'),
                'last_response' => $response,
                'next_retry_at' => $success ? null : $this->calculateNextRetryTime($task->retry_cnt + 1),
            ]);

            return $success;
        } catch (\Throwable $e) {
            Log::error('发送通知失败', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $this->notifyTaskRepository->updateById($task->id, [
                'retry_cnt' => $task->retry_cnt + 1,
                'last_notify_at' => date('Y-m-d H:i:s'),
                'last_response' => $e->getMessage(),
                'next_retry_at' => $this->calculateNextRetryTime($task->retry_cnt + 1),
            ]);
            
            return false;
        }
    }
    
    /**
     * 计算下次重试时间（指数退避）
     */
    private function calculateNextRetryTime(int $retryCount): string
    {
        $intervals = [60, 300, 900, 3600]; // 1分钟、5分钟、15分钟、1小时
        $interval = $intervals[min($retryCount - 1, count($intervals) - 1)] ?? 3600;
        return date('Y-m-d H:i:s', time() + $interval);
    }
}

