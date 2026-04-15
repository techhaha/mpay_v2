<?php

namespace app\service\payment\trace;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\exception\ValidationException;
use app\model\payment\BizOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\account\ledger\MerchantAccountLedgerRepository;
use app\repository\ops\log\PayCallbackLogRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\repository\payment\settlement\SettlementOrderRepository;

/**
 * 跨域交易追踪查询服务。
 */
class TradeTraceService extends BaseService
{
    public function __construct(
        protected TradeTraceReportService $tradeTraceReportService,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository,
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository,
        protected PayCallbackLogRepository $payCallbackLogRepository
    ) {
    }

    /**
     * 根据追踪号查询完整交易链路。
     */
    public function queryByTraceNo(string $traceNo): array
    {
        $traceNo = trim($traceNo);
        if ($traceNo === '') {
            throw new ValidationException('trace_no 不能为空');
        }

        $matchedBy = 'trace_no';
        $bizOrder = $this->bizOrderRepository->findByTraceNo($traceNo);
        if (!$bizOrder) {
            $bizOrder = $this->bizOrderRepository->findByBizNo($traceNo);
            if ($bizOrder) {
                $matchedBy = 'biz_no';
            }
        }

        $resolvedTraceNo = $traceNo;
        if ($bizOrder) {
            $resolvedTraceNo = (string) ($bizOrder->trace_no ?: $bizOrder->biz_no);
        }

        $payOrders = $this->loadPayOrders($resolvedTraceNo, $bizOrder);
        $refundOrders = $this->loadRefundOrders($resolvedTraceNo, $bizOrder);
        $settlementOrders = $this->loadSettlementOrders($resolvedTraceNo);

        if (!$bizOrder) {
            $bizOrder = $this->deriveBizOrder($payOrders, $refundOrders);
            if ($bizOrder) {
                $matchedBy = $matchedBy === 'trace_no' ? 'derived' : $matchedBy;
                $resolvedTraceNo = (string) ($bizOrder->trace_no ?: $bizOrder->biz_no);
                $payOrders = $this->loadPayOrders($resolvedTraceNo, $bizOrder);
                $refundOrders = $this->loadRefundOrders($resolvedTraceNo, $bizOrder);
                $settlementOrders = $this->loadSettlementOrders($resolvedTraceNo);
            }
        }

        $payCallbacks = $this->loadPayCallbacks($payOrders);
        $accountLedgers = $this->loadLedgers($resolvedTraceNo, $bizOrder, $payOrders, $refundOrders, $settlementOrders);
        if (empty($accountLedgers) && $resolvedTraceNo !== $traceNo) {
            $accountLedgers = $this->loadLedgers($traceNo, $bizOrder, $payOrders, $refundOrders, $settlementOrders);
        }

        if (
            $bizOrder === null
            && empty($payOrders)
            && empty($refundOrders)
            && empty($settlementOrders)
            && empty($accountLedgers)
            && empty($payCallbacks)
        ) {
            return [];
        }

        return [
            'trace_no' => $traceNo,
            'resolved_trace_no' => $resolvedTraceNo,
            'matched_by' => $matchedBy,
            'biz_order' => $bizOrder,
            'pay_orders' => $payOrders,
            'refund_orders' => $refundOrders,
            'settlement_orders' => $settlementOrders,
            'account_ledgers' => $accountLedgers,
            'pay_callbacks' => $payCallbacks,
            'summary' => $this->tradeTraceReportService->buildSummary($bizOrder, $payOrders, $refundOrders, $settlementOrders, $accountLedgers, $payCallbacks),
            'timeline' => $this->tradeTraceReportService->buildTimeline($bizOrder, $payOrders, $refundOrders, $settlementOrders, $accountLedgers, $payCallbacks),
        ];
    }

    /**
     * 加载支付单列表。
     */
    private function loadPayOrders(string $traceNo, ?BizOrder $bizOrder): array
    {
        $items = $this->collectionToArray($this->payOrderRepository->listByTraceNo($traceNo));
        if (!empty($items)) {
            return $items;
        }

        if ($bizOrder) {
            return $this->collectionToArray($this->payOrderRepository->listByBizNo((string) $bizOrder->biz_no));
        }

        return [];
    }

    /**
     * 加载退款单列表。
     */
    private function loadRefundOrders(string $traceNo, ?BizOrder $bizOrder): array
    {
        $items = $this->collectionToArray($this->refundOrderRepository->listByTraceNo($traceNo));
        if (!empty($items)) {
            return $items;
        }

        if ($bizOrder) {
            return $this->collectionToArray($this->refundOrderRepository->listByBizNo((string) $bizOrder->biz_no));
        }

        return [];
    }

    /**
     * 加载清结算单列表。
     */
    private function loadSettlementOrders(string $traceNo): array
    {
        return $this->collectionToArray($this->settlementOrderRepository->listByTraceNo($traceNo));
    }

    /**
     * 加载支付回调日志列表。
     */
    private function loadPayCallbacks(array $payOrders): array
    {
        $callbacks = [];
        foreach ($payOrders as $payOrder) {
            foreach ($this->payCallbackLogRepository->listByPayNo((string) $payOrder->pay_no) as $callback) {
                $callbacks[] = [
                    'id' => (int) ($callback->id ?? 0),
                    'pay_no' => (string) $callback->pay_no,
                    'channel_id' => (int) $callback->channel_id,
                    'callback_type' => (int) $callback->callback_type,
                    'callback_type_text' => (string) (NotifyConstant::callbackTypeMap()[$callback->callback_type] ?? ''),
                    'request_data' => $callback->request_data,
                    'verify_status' => (int) $callback->verify_status,
                    'verify_status_text' => (string) (NotifyConstant::verifyStatusMap()[$callback->verify_status] ?? ''),
                    'process_status' => (int) $callback->process_status,
                    'process_status_text' => (string) (NotifyConstant::processStatusMap()[$callback->process_status] ?? ''),
                    'process_result' => $callback->process_result,
                    'created_at' => $callback->created_at,
                ];
            }
        }

        usort($callbacks, static function ($left, $right): int {
            return ($right['id'] ?? 0) <=> ($left['id'] ?? 0);
        });

        return $callbacks;
    }

    /**
     * 加载资金流水列表。
     */
    private function loadLedgers(string $traceNo, ?BizOrder $bizOrder, array $payOrders, array $refundOrders, array $settlementOrders): array
    {
        $ledgers = [];
        $seen = [];

        foreach ($this->collectionToArray($this->merchantAccountLedgerRepository->listByTraceNo($traceNo)) as $ledger) {
            $seen[(string) $ledger->ledger_no] = true;
            $ledgers[] = $ledger;
        }

        $bizNos = [];
        if ($bizOrder) {
            $bizNos[] = (string) $bizOrder->biz_no;
        }

        foreach ($payOrders as $payOrder) {
            $bizNos[] = (string) ($payOrder->pay_no ?? '');
        }

        foreach ($refundOrders as $refundOrder) {
            $bizNos[] = (string) ($refundOrder->refund_no ?? '');
        }

        foreach ($settlementOrders as $settlementOrder) {
            $bizNos[] = (string) ($settlementOrder->settle_no ?? '');
        }

        $bizNos = array_values(array_filter(array_unique($bizNos)));
        foreach ($bizNos as $bizNo) {
            foreach ($this->collectionToArray($this->merchantAccountLedgerRepository->listByBizNo($bizNo)) as $ledger) {
                $ledgerNo = (string) ($ledger->ledger_no ?? '');
                if ($ledgerNo !== '' && isset($seen[$ledgerNo])) {
                    continue;
                }
                if ($ledgerNo !== '') {
                    $seen[$ledgerNo] = true;
                }
                $ledgers[] = $ledger;
            }
        }

        usort($ledgers, static function ($left, $right): int {
            return ($right->id ?? 0) <=> ($left->id ?? 0);
        });

        return $ledgers;
    }

    /**
     * 从支付单或退款单反推出业务单。
     */
    private function deriveBizOrder(array $payOrders, array $refundOrders): ?BizOrder
    {
        if (!empty($payOrders)) {
            $bizNo = (string) ($payOrders[0]->biz_no ?? '');
            if ($bizNo !== '') {
                $bizOrder = $this->bizOrderRepository->findByBizNo($bizNo);
                if ($bizOrder) {
                    return $bizOrder;
                }
            }
        }

        if (!empty($refundOrders)) {
            $bizNo = (string) ($refundOrders[0]->biz_no ?? '');
            if ($bizNo !== '') {
                $bizOrder = $this->bizOrderRepository->findByBizNo($bizNo);
                if ($bizOrder) {
                    return $bizOrder;
                }
            }
        }

        return null;
    }

    /**
     * 将可迭代对象转换为普通数组。
     */
    private function collectionToArray(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item;
        }

        return $rows;
    }

}
