<?php

namespace app\service\payment\trace;

use app\common\base\BaseService;
use app\common\constant\LedgerConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\model\payment\BizOrder;

/**
 * 跨域交易追踪结果组装服务。
 *
 * 负责把追踪查询到的原始记录组装成摘要和时间线。
 */
class TradeTraceReportService extends BaseService
{
    /**
     * 汇总追踪统计数据。
     *
     * @param BizOrder|null $bizOrder 业务订单
     * @param array $payOrders 支付订单列表
     * @param array $refundOrders 退款订单列表
     * @param array $settlementOrders 清算订单列表
     * @param array $accountLedgers 账户流水列表
     * @param array $payCallbacks 支付回调列表
     * @return array<string, int|bool> 汇总统计
     */
    public function buildSummary(?BizOrder $bizOrder, array $payOrders, array $refundOrders, array $settlementOrders, array $accountLedgers, array $payCallbacks): array
    {
        return [
            'has_biz_order' => $bizOrder !== null,
            'pay_order_count' => count($payOrders),
            'refund_order_count' => count($refundOrders),
            'settlement_order_count' => count($settlementOrders),
            'ledger_count' => count($accountLedgers),
            'callback_count' => count($payCallbacks),
            'pay_amount_total' => $this->sumBy($payOrders, 'pay_amount'),
            'refund_amount_total' => $this->sumBy($refundOrders, 'refund_amount'),
            'settlement_accounted_total' => $this->sumBy($settlementOrders, 'accounted_amount'),
            'ledger_amount_total' => $this->sumBy($accountLedgers, 'amount'),
        ];
    }

    /**
     * 根据关联记录组装追踪时间线。
     *
     * @param BizOrder|null $bizOrder 业务订单
     * @param array $payOrders 支付订单列表
     * @param array $refundOrders 退款订单列表
     * @param array $settlementOrders 清算订单列表
     * @param array $accountLedgers 账户流水列表
     * @param array $payCallbacks 支付回调列表
     * @return array<int, array<string, mixed>> 时间线事件
     */
    public function buildTimeline(?BizOrder $bizOrder, array $payOrders, array $refundOrders, array $settlementOrders, array $accountLedgers, array $payCallbacks): array
    {
        $events = [];
        $sortOrder = 0;

        if ($bizOrder) {
            $this->pushEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'created', $bizOrder->created_at, [
                'label' => '业务单创建',
                'status_text' => '创建',
                'biz_no' => (string) $bizOrder->biz_no,
                'merchant_order_no' => (string) $bizOrder->merchant_order_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'paid', $bizOrder->paid_at, [
                'label' => '业务单已支付',
                'status_text' => '成功',
                'biz_no' => (string) $bizOrder->biz_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'closed', $bizOrder->closed_at, [
                'label' => '业务单已关闭',
                'status_text' => '关闭',
                'biz_no' => (string) $bizOrder->biz_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'failed', $bizOrder->failed_at, [
                'label' => '业务单失败',
                'status_text' => '失败',
                'biz_no' => (string) $bizOrder->biz_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'timeout', $bizOrder->timeout_at, [
                'label' => '业务单超时',
                'status_text' => '超时',
                'biz_no' => (string) $bizOrder->biz_no,
            ]);
        }

        foreach ($payOrders as $payOrder) {
            $payNo = (string) $payOrder->pay_no;
            $statusTextMap = TradeConstant::orderStatusMap();
            $this->pushEvent($events, $sortOrder, 'pay_order', $payNo, 'created', $payOrder->request_at, [
                'label' => '支付单创建',
                'status_text' => '创建',
                'pay_no' => $payNo,
                'biz_no' => (string) $payOrder->biz_no,
                'attempt_no' => (int) ($payOrder->attempt_no ?? 0),
            ]);
            $this->pushEvent($events, $sortOrder, 'pay_order', $payNo, 'paid', $payOrder->paid_at, [
                'label' => '支付成功',
                'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_SUCCESS] ?? '成功'),
                'pay_no' => $payNo,
                'biz_no' => (string) $payOrder->biz_no,
                'channel_id' => (int) $payOrder->channel_id,
            ]);
            $this->pushEvent($events, $sortOrder, 'pay_order', $payNo, 'closed', $payOrder->closed_at, [
                'label' => '支付关闭',
                'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_CLOSED] ?? '关闭'),
                'pay_no' => $payNo,
                'biz_no' => (string) $payOrder->biz_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'pay_order', $payNo, 'failed', $payOrder->failed_at, [
                'label' => '支付失败',
                'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_FAILED] ?? '失败'),
                'pay_no' => $payNo,
                'biz_no' => (string) $payOrder->biz_no,
                'channel_error_msg' => (string) ($payOrder->channel_error_msg ?? ''),
            ]);
            $this->pushEvent($events, $sortOrder, 'pay_order', $payNo, 'timeout', $payOrder->timeout_at, [
                'label' => '支付超时',
                'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_TIMEOUT] ?? '超时'),
                'pay_no' => $payNo,
                'biz_no' => (string) $payOrder->biz_no,
            ]);
        }

        foreach ($refundOrders as $refundOrder) {
            $refundNo = (string) $refundOrder->refund_no;
            $statusTextMap = TradeConstant::refundStatusMap();
            $this->pushEvent($events, $sortOrder, 'refund_order', $refundNo, 'created', $refundOrder->request_at, [
                'label' => '退款单创建',
                'status_text' => '创建',
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'refund_order', $refundNo, 'processing', $refundOrder->processing_at, [
                'label' => '退款处理中',
                'status_text' => (string) ($statusTextMap[TradeConstant::REFUND_STATUS_PROCESSING] ?? '处理中'),
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
                'retry_count' => (int) ($refundOrder->retry_count ?? 0),
            ]);
            $this->pushEvent($events, $sortOrder, 'refund_order', $refundNo, 'success', $refundOrder->succeeded_at, [
                'label' => '退款成功',
                'status_text' => (string) ($statusTextMap[TradeConstant::REFUND_STATUS_SUCCESS] ?? '成功'),
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
            ]);
            $this->pushEvent($events, $sortOrder, 'refund_order', $refundNo, 'failed', $refundOrder->failed_at, [
                'label' => '退款失败',
                'status_text' => (string) ($statusTextMap[TradeConstant::REFUND_STATUS_FAILED] ?? '失败'),
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
                'last_error' => (string) ($refundOrder->last_error ?? ''),
            ]);
        }

        foreach ($settlementOrders as $settlementOrder) {
            $settleNo = (string) $settlementOrder->settle_no;
            $statusTextMap = TradeConstant::settlementStatusMap();
            $this->pushEvent($events, $sortOrder, 'settlement_order', $settleNo, 'generated', $settlementOrder->generated_at, [
                'label' => '清结算单生成',
                'status_text' => '生成',
                'settle_no' => $settleNo,
            ]);
            $this->pushEvent($events, $sortOrder, 'settlement_order', $settleNo, 'accounted', $settlementOrder->accounted_at, [
                'label' => '清结算入账',
                'status_text' => '入账',
                'settle_no' => $settleNo,
                'accounted_amount' => (int) ($settlementOrder->accounted_amount ?? 0),
            ]);
            $this->pushEvent($events, $sortOrder, 'settlement_order', $settleNo, 'completed', $settlementOrder->completed_at, [
                'label' => '清结算完成',
                'status_text' => (string) ($statusTextMap[TradeConstant::SETTLEMENT_STATUS_SETTLED] ?? '已清算'),
                'settle_no' => $settleNo,
            ]);
            $this->pushEvent($events, $sortOrder, 'settlement_order', $settleNo, 'failed', $settlementOrder->failed_at, [
                'label' => '清结算失败',
                'status_text' => (string) ($statusTextMap[TradeConstant::SETTLEMENT_STATUS_REVERSED] ?? '已冲正'),
                'settle_no' => $settleNo,
                'fail_reason' => (string) ($settlementOrder->fail_reason ?? ''),
            ]);
        }

        foreach ($accountLedgers as $ledger) {
            $this->pushEvent($events, $sortOrder, 'ledger', (string) $ledger->ledger_no, 'recorded', $ledger->created_at, [
                'label' => '资金流水',
                'status_text' => (string) (LedgerConstant::eventTypeMap()[$ledger->event_type] ?? '流水'),
                'ledger_no' => (string) $ledger->ledger_no,
                'biz_no' => (string) $ledger->biz_no,
                'biz_type' => (int) $ledger->biz_type,
                'biz_type_text' => (string) (LedgerConstant::bizTypeMap()[$ledger->biz_type] ?? ''),
                'direction' => (int) $ledger->direction,
                'direction_text' => (string) (LedgerConstant::directionMap()[$ledger->direction] ?? ''),
                'amount' => (int) $ledger->amount,
            ]);
        }

        foreach ($payCallbacks as $callback) {
            $this->pushEvent($events, $sortOrder, 'pay_callback', (string) ($callback['pay_no'] ?? ''), 'received', $callback['created_at'] ?? null, [
                'label' => '支付回调',
                'status_text' => (string) ($callback['callback_type_text'] ?? '回调'),
                'pay_no' => (string) ($callback['pay_no'] ?? ''),
                'channel_id' => (int) ($callback['channel_id'] ?? 0),
                'verify_status' => (int) ($callback['verify_status'] ?? 0),
                'verify_status_text' => (string) ($callback['verify_status_text'] ?? ''),
                'process_status' => (int) ($callback['process_status'] ?? 0),
                'process_status_text' => (string) ($callback['process_status_text'] ?? ''),
            ]);
        }

        usort($events, static function (array $left, array $right): int {
            $cmp = strcmp((string) ($left['at'] ?? ''), (string) ($right['at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($left['_sort_order'] ?? 0) <=> ($right['_sort_order'] ?? 0);
        });

        foreach ($events as &$event) {
            unset($event['_sort_order']);
        }
        unset($event);

        return array_values($events);
    }

    /**
     * 追加一条时间线事件。
     *
     * @param array<int, array<string, mixed>> $events 事件列表
     * @param int $sortOrder 当前排序号
     * @param string $type 事件类型
     * @param string $sourceNo 事件来源单号
     * @param string $status 事件状态
     * @param \DateTimeInterface|int|string|float|null $at 事件时间
     * @param array<string, mixed> $payload 事件载荷
     * @return void
     */
    private function pushEvent(array &$events, int &$sortOrder, string $type, string $sourceNo, string $status, \DateTimeInterface|int|string|float|null $at, array $payload = []): void
    {
        $atText = $this->formatDateTime($at);
        if ($atText === '') {
            return;
        }

        $events[] = [
            'type' => $type,
            'source_no' => $sourceNo,
            'status' => $status,
            'status_text' => (string) ($payload['status_text'] ?? ''),
            'label' => (string) ($payload['label'] ?? ''),
            'at' => $atText,
            'payload' => $payload,
            '_sort_order' => $sortOrder++,
        ];
    }

    /**
     * 汇总模型列表中的数值字段。
     *
     * @param array $items 模型列表
     * @param string $field 字段名
     * @return int 汇总值
     */
    private function sumBy(array $items, string $field): int
    {
        $total = 0;
        foreach ($items as $item) {
            $total += (int) ($item->{$field} ?? 0);
        }

        return $total;
    }
}



