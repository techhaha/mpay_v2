<?php

namespace app\listener;

use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\PaymentQueueService;
use app\service\payment\settlement\SettlementAutomationService;
use support\Log;

/**
 * 支付成功后的清算监听器。
 *
 * 平台代收支付成功后生成清算单；满足自动入账策略时投递清算入账队列。
 */
class PaymentSettlementListener
{
    /**
     * 构造方法。
     *
     * @param SettlementAutomationService $settlementAutomationService 清算自动化服务
     * @param PaymentQueueService $paymentQueueService 支付队列服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @return void
     */
    public function __construct(
        protected SettlementAutomationService $settlementAutomationService,
        protected PaymentQueueService $paymentQueueService,
        protected PayOrderRepository $payOrderRepository
    ) {
    }

    /**
     * 支付成功后生成清算单。
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
                Log::warning('[PaymentSettlementListener] 支付成功事件缺少可用支付单');
                return;
            }

            $settlementOrder = $this->settlementAutomationService->createForPaidPayOrder($payOrder);
            if ($settlementOrder && $this->settlementAutomationService->shouldAutoComplete($settlementOrder)) {
                $this->paymentQueueService->sendSettlementComplete((string) $settlementOrder->settle_no);
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentSettlementListener] 清算生成失败 event=%s pay_no=%s error=%s',
                $eventName,
                (string) ($payload['pay_no'] ?? ''),
                $e->getMessage()
            ));
        }
    }
}
