<?php

namespace app\listener;

use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\model\payment\SettlementOrder;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\runtime\MerchantNotifyDispatcherService;
use app\service\payment\runtime\PaymentQueueService;
use support\Log;

/**
 * 支付域商户通知监听器。
 *
 * 聚合支付、退款、清算等会触发商户通知的事件处理，并把实际 HTTP 通知交给队列消费。
 */
class PaymentMerchantNotifyListener
{
    /**
     * 构造方法。
     *
     * @param MerchantNotifyDispatcherService $merchantNotifyDispatcherService 商户通知派发服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param SettlementOrderRepository $settlementOrderRepository 清算单仓库
     * @param PaymentQueueService $paymentQueueService 支付队列服务
     * @return void
     */
    public function __construct(
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService,
        protected PayOrderRepository $payOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository,
        protected PaymentQueueService $paymentQueueService
    ) {
    }

    /**
     * 支付成功后创建商户通知任务并投递队列。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onPayOrderSucceeded(array $payload = [], string $eventName = ''): void
    {
        try {
            $payOrder = $payload['pay_order'] ?? null;
            if (!$payOrder instanceof PayOrder) {
                $payNo = trim((string) ($payload['pay_no'] ?? ''));
                $payOrder = $payNo !== '' ? $this->payOrderRepository->findByPayNo($payNo) : null;
            }

            if (!$payOrder instanceof PayOrder) {
                Log::warning('[PaymentMerchantNotifyListener] 支付成功事件缺少可用支付单');
                return;
            }

            $task = $this->merchantNotifyDispatcherService->enqueuePaySuccess($payOrder);
            if ($task) {
                $this->paymentQueueService->sendMerchantNotify((string) $task->notify_no);
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentMerchantNotifyListener] 商户支付通知创建失败 event=%s pay_no=%s error=%s',
                $eventName,
                (string) ($payload['pay_no'] ?? ''),
                $e->getMessage()
            ));
        }
    }

    /**
     * 退款成功后创建商户通知任务并投递队列。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onRefundOrderSucceeded(array $payload = [], string $eventName = ''): void
    {
        try {
            $refundOrder = $payload['refund_order'] ?? null;
            if (!$refundOrder instanceof RefundOrder) {
                $refundNo = trim((string) ($payload['refund_no'] ?? ''));
                $refundOrder = $refundNo !== '' ? $this->refundOrderRepository->findByRefundNo($refundNo) : null;
            }

            if (!$refundOrder instanceof RefundOrder) {
                Log::warning('[PaymentMerchantNotifyListener] 退款成功事件缺少可用退款单');
                return;
            }

            $task = $this->merchantNotifyDispatcherService->enqueueRefundSuccess($refundOrder);
            if ($task) {
                $this->paymentQueueService->sendMerchantNotify((string) $task->notify_no);
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentMerchantNotifyListener] 商户退款通知创建失败 event=%s refund_no=%s error=%s',
                $eventName,
                (string) ($payload['refund_no'] ?? ''),
                $e->getMessage()
            ));
        }
    }

    /**
     * 清算成功后创建商户通知任务并投递队列。
     *
     * @param array<string, mixed> $payload 事件载荷
     * @param string $eventName 事件名称
     * @return void
     */
    public function onSettlementOrderSucceeded(array $payload = [], string $eventName = ''): void
    {
        try {
            $settlementOrder = $payload['settlement_order'] ?? null;
            if (!$settlementOrder instanceof SettlementOrder) {
                $settleNo = trim((string) ($payload['settle_no'] ?? ''));
                $settlementOrder = $settleNo !== '' ? $this->settlementOrderRepository->findBySettleNo($settleNo) : null;
            }

            if (!$settlementOrder instanceof SettlementOrder) {
                Log::warning('[PaymentMerchantNotifyListener] 清算成功事件缺少可用清算单');
                return;
            }

            $task = $this->merchantNotifyDispatcherService->enqueueSettlementSuccess($settlementOrder);
            if ($task) {
                $this->paymentQueueService->sendMerchantNotify((string) $task->notify_no);
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentMerchantNotifyListener] 商户清算通知创建失败 event=%s settle_no=%s error=%s',
                $eventName,
                (string) ($payload['settle_no'] ?? ''),
                $e->getMessage()
            ));
        }
    }
}
