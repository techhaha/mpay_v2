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
 *
 * @property TradeTraceReportService $tradeTraceReportService 交易追踪报表服务
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 * @property SettlementOrderRepository $settlementOrderRepository 结算订单仓库
 * @property MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
 * @property PayCallbackLogRepository $payCallbackLogRepository 支付回调日志仓库
 */
class TradeTraceService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param TradeTraceReportService $tradeTraceReportService 交易追踪报表服务
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param SettlementOrderRepository $settlementOrderRepository 结算订单仓库
     * @param MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
     * @param PayCallbackLogRepository $payCallbackLogRepository 支付回调日志仓库
     * @return void
     */
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
     *
     * @param string $traceNo 追踪号
     * @return array{trace_no: string, resolved_trace_no: string, matched_by: string, biz_order: BizOrder|null, pay_orders: array, refund_orders: array, settlement_orders: array, account_ledgers: array, pay_callbacks: array, summary: array, timeline: array} 追踪结果
     * @throws ValidationException
     */
    public function queryByTraceNo(string $traceNo): array
    {
        $traceNo = trim($traceNo);
        if ($traceNo === '') {
            throw new ValidationException('trace_no 不能为空');
        }

        $matchedBy = 'trace_no';
        // 先按追踪号找，找不到再用业务单号兜底，尽量把同一条链路串起来。
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
            // 如果主单没直接查到，就从支付单或退款单反推业务单，保证追踪页尽量有完整链路。
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
     *
     * @param string $traceNo 追踪号
     * @param BizOrder|null $bizOrder 业务订单
     * @return array<int, object> 支付订单列表
     */
    private function loadPayOrders(string $traceNo, ?BizOrder $bizOrder): array
    {
        // 优先按 trace_no 查，缺失时再回到 biz_no，兼容早期单据没有完整追踪号的情况。
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
     *
     * @param string $traceNo 追踪号
     * @param BizOrder|null $bizOrder 业务订单
     * @return array<int, object> 退款订单列表
     */
    private function loadRefundOrders(string $traceNo, ?BizOrder $bizOrder): array
    {
        // 退款单同样先按追踪号查，再用业务单号兜底。
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
     *
     * @param string $traceNo 追踪号
     * @return array<int, object> 清算订单列表
     */
    private function loadSettlementOrders(string $traceNo): array
    {
        return $this->collectionToArray($this->settlementOrderRepository->listByTraceNo($traceNo));
    }

    /**
     * 加载支付回调日志列表。
     *
     * @param array<int, object> $payOrders 支付订单列表
     * @return array<int, array<string, mixed>> 支付回调列表
     */
    private function loadPayCallbacks(array $payOrders): array
    {
        $callbacks = [];
        foreach ($payOrders as $payOrder) {
            // 同一追踪号下可能有多次回调记录，这里把每笔支付单的回调都收进来统一展示。
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
            // 新的回调日志排在前面，时间线页面直接从近到远看。
            return ($right['id'] ?? 0) <=> ($left['id'] ?? 0);
        });

        return $callbacks;
    }

    /**
     * 加载资金流水列表。
     *
     * @param string $traceNo 追踪号
     * @param BizOrder|null $bizOrder 业务订单
     * @param array<int, object> $payOrders 支付订单列表
     * @param array<int, object> $refundOrders 退款订单列表
     * @param array<int, object> $settlementOrders 清算订单列表
     * @return array<int, object> 资金流水列表
     */
    private function loadLedgers(string $traceNo, ?BizOrder $bizOrder, array $payOrders, array $refundOrders, array $settlementOrders): array
    {
        $ledgers = [];
        $seen = [];

        // 先合并 trace_no 命中的流水，再补查相关业务单号下的流水并去重。
        foreach ($this->collectionToArray($this->merchantAccountLedgerRepository->listByTraceNo($traceNo)) as $ledger) {
            $seen[(string) $ledger->ledger_no] = true;
            $ledgers[] = $ledger;
        }

        $bizNos = [];
        // 资金流水有时挂在业务单号，有时挂在支付单号、退款单号或清算单号上，这里一并纳入兜底查询。
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
                // 同一笔流水可能同时被 trace_no 和 biz_no 命中，这里只保留一份。
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
     *
     * @param array<int, object> $payOrders 支付订单列表
     * @param array<int, object> $refundOrders 退款订单列表
     * @return BizOrder|null 业务订单模型
     */
    private function deriveBizOrder(array $payOrders, array $refundOrders): ?BizOrder
    {
        if (!empty($payOrders)) {
            // 先从支付单反推业务单，支付单通常比退款单更早、更稳定。
            $bizNo = (string) ($payOrders[0]->biz_no ?? '');
            if ($bizNo !== '') {
                $bizOrder = $this->bizOrderRepository->findByBizNo($bizNo);
                if ($bizOrder) {
                    return $bizOrder;
                }
            }
        }

        if (!empty($refundOrders)) {
            // 没有支付单时，再用退款单反推业务单作为兜底。
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
     *
     * @param iterable $items 可迭代对象
     * @return array<int, mixed> 普通数组
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
