<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\LedgerConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;

/**
 * 支付单结果组装服务。
 *
 * 负责支付单列表、详情页和时间线的展示字段格式化。
 */
class PayOrderReportService extends BaseService
{
    /**
     * 格式化支付订单行，统一输出前端需要的中文字段。
     *
     * 该方法只做展示层字段补齐，不修改原始业务语义。
     *
     * @param array<string, mixed> $row 原始查询行
     * @return array<string, mixed> 格式化后的支付单行
     */
    public function formatPayOrderRow(array $row): array
    {
        $row['merchant_group_name'] = trim((string) ($row['merchant_group_name'] ?? '')) ?: '未分组';
        $row['merchant_name'] = trim((string) ($row['merchant_name'] ?? '')) ?: '未知商户';
        $row['merchant_short_name'] = trim((string) ($row['merchant_short_name'] ?? ''));
        $row['pay_type_name'] = trim((string) ($row['pay_type_name'] ?? '')) ?: '未知方式';
        $row['channel_name'] = trim((string) ($row['channel_name'] ?? '')) ?: '未知通道';
        $row['biz_status_text'] = $this->textFromMap((int) ($row['biz_status'] ?? -1), TradeConstant::orderStatusMap());

        $row['status_text'] = $this->textFromMap((int) ($row['status'] ?? -1), TradeConstant::orderStatusMap());
        $row['service_fee_status_text'] = $this->textFromMap((int) ($row['service_fee_status'] ?? -1), TradeConstant::serviceFeeStatusMap());
        $row['settlement_status_text'] = $this->textFromMap((int) ($row['settlement_status'] ?? -1), TradeConstant::settlementStatusMap());
        $row['callback_status_text'] = $this->textFromMap((int) ($row['callback_status'] ?? -1), NotifyConstant::processStatusMap());
        $row['channel_type_text'] = $this->textFromMap((int) ($row['channel_type'] ?? -1), RouteConstant::channelTypeMap());
        $row['channel_mode_text'] = $this->textFromMap((int) ($row['channel_mode'] ?? -1), RouteConstant::channelModeMap());

        $row['pay_amount_text'] = $this->formatAmount((int) ($row['pay_amount'] ?? 0));
        $row['service_fee_amount_text'] = $this->formatAmount((int) ($row['service_fee_amount'] ?? 0));
        $row['biz_order_amount_text'] = $this->formatAmount((int) ($row['biz_order_amount'] ?? 0));
        $row['biz_paid_amount_text'] = $this->formatAmount((int) ($row['biz_paid_amount'] ?? 0));
        $row['biz_refund_amount_text'] = $this->formatAmount((int) ($row['biz_refund_amount'] ?? 0));

        $row['request_at_text'] = $this->formatDateTime($row['request_at'] ?? null, '—');
        $row['paid_at_text'] = $this->formatDateTime($row['paid_at'] ?? null, '—');
        $row['expire_at_text'] = $this->formatDateTime($row['expire_at'] ?? null, '—');
        $row['closed_at_text'] = $this->formatDateTime($row['closed_at'] ?? null, '—');
        $row['failed_at_text'] = $this->formatDateTime($row['failed_at'] ?? null, '—');
        $row['timeout_at_text'] = $this->formatDateTime($row['timeout_at'] ?? null, '—');
        $row['biz_expire_at_text'] = $this->formatDateTime($row['biz_expire_at'] ?? null, '—');
        $row['biz_paid_at_text'] = $this->formatDateTime($row['biz_paid_at'] ?? null, '—');
        $row['biz_closed_at_text'] = $this->formatDateTime($row['biz_closed_at'] ?? null, '—');
        $row['biz_failed_at_text'] = $this->formatDateTime($row['biz_failed_at'] ?? null, '—');
        $row['biz_timeout_at_text'] = $this->formatDateTime($row['biz_timeout_at'] ?? null, '—');

        return $row;
    }

    /**
     * 构造支付时间线。
     *
     * 按创建、成功、关闭、失败、超时的顺序输出，方便前端直接渲染状态流转。
     *
     * @param PayOrder $payOrder 支付订单
     * @return array<int, array<string, mixed>> 支付时间线
     */
    public function buildPayTimeline(PayOrder $payOrder): array
    {
        // 只保留真实发生过的节点，未触发的状态直接过滤掉，避免时间线里出现空占位。
        return array_values(array_filter([
            [
                'status' => 'created',
                'label' => '支付单创建',
                'at' => $this->formatDateTime($payOrder->request_at ?? $payOrder->created_at ?? null, '—'),
            ],
            $payOrder->paid_at ? [
                'status' => 'success',
                'label' => '支付成功',
                'at' => $this->formatDateTime($payOrder->paid_at, '—'),
            ] : null,
            $payOrder->closed_at ? [
                'status' => 'closed',
                'label' => '支付关闭',
                'at' => $this->formatDateTime($payOrder->closed_at, '—'),
                'reason' => '',
            ] : null,
            $payOrder->failed_at ? [
                'status' => 'failed',
                'label' => '支付失败',
                'at' => $this->formatDateTime($payOrder->failed_at, '—'),
                'reason' => (string) $payOrder->channel_error_msg,
            ] : null,
            $payOrder->timeout_at ? [
                'status' => 'timeout',
                'label' => '支付超时',
                'at' => $this->formatDateTime($payOrder->timeout_at, '—'),
                'reason' => '',
            ] : null,
        ]));
    }

    /**
     * 构造支付单排障时间线。
     *
     * 覆盖业务单、支付单、上游回调、商户通知、退款、清算和资金流水，
     * 让后台详情页能按实际发生时间还原交易链路。
     *
     * @param PayOrder $payOrder 支付订单
     * @param BizOrder|null $bizOrder 业务订单
     * @param array<int, \app\model\payment\RefundOrder> $refundOrders 退款单列表
     * @param array<int, \app\model\payment\SettlementOrder> $settlementOrders 清算单列表
     * @param array<int, \app\model\merchant\MerchantAccountLedger> $accountLedgers 资金流水列表
     * @param array<int, array<string, mixed>> $notifyTasks 通知任务列表
     * @param array<int, array<string, mixed>> $callbackLogs 回调日志列表
     * @param array<int, array<string, mixed>> $channelQueryLogs 主动查单日志列表
     * @param array<int, array<string, mixed>> $operationLogs 后台操作日志列表
     * @return array<int, array<string, mixed>> 排障时间线
     */
    public function buildTroubleshootingTimeline(
        PayOrder $payOrder,
        ?BizOrder $bizOrder,
        array $refundOrders,
        array $settlementOrders,
        array $accountLedgers,
        array $notifyTasks,
        array $callbackLogs,
        array $channelQueryLogs = [],
        array $operationLogs = []
    ): array {
        $events = [];
        $sortOrder = 0;

        if ($bizOrder) {
            $this->pushTimelineEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'created', $bizOrder->created_at, [
                'label' => '业务单创建',
                'status_text' => '创建',
                'description' => trim((string) $bizOrder->merchant_order_no) !== '' ? '商户单号 ' . (string) $bizOrder->merchant_order_no : '',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'success', $bizOrder->paid_at, [
                'label' => '业务单支付完成',
                'status_text' => '成功',
                'description' => '已付金额 ' . $this->formatAmount((int) ($bizOrder->paid_amount ?? 0)) . ' 元',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'closed', $bizOrder->closed_at, [
                'label' => '业务单关闭',
                'status_text' => '关闭',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'failed', $bizOrder->failed_at, [
                'label' => '业务单失败',
                'status_text' => '失败',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'biz_order', (string) $bizOrder->biz_no, 'timeout', $bizOrder->timeout_at, [
                'label' => '业务单超时',
                'status_text' => '超时',
            ]);
        }

        $statusTextMap = TradeConstant::orderStatusMap();
        $this->pushTimelineEvent($events, $sortOrder, 'pay_order', (string) $payOrder->pay_no, 'created', $payOrder->request_at ?? $payOrder->created_at, [
            'label' => '支付单创建',
            'status_text' => '创建',
            'description' => '支付金额 ' . $this->formatAmount((int) ($payOrder->pay_amount ?? 0)) . ' 元',
            'payload' => [
                'biz_no' => (string) $payOrder->biz_no,
                'attempt_no' => (int) ($payOrder->attempt_no ?? 0),
                'channel_id' => (int) ($payOrder->channel_id ?? 0),
            ],
        ]);
        $this->pushTimelineEvent($events, $sortOrder, 'pay_order', (string) $payOrder->pay_no, 'success', $payOrder->paid_at, [
            'label' => '支付成功',
            'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_SUCCESS] ?? '成功'),
            'description' => trim((string) ($payOrder->channel_trade_no ?? '')) !== '' ? '通道流水号 ' . (string) $payOrder->channel_trade_no : '',
        ]);
        $this->pushTimelineEvent($events, $sortOrder, 'pay_order', (string) $payOrder->pay_no, 'closed', $payOrder->closed_at, [
            'label' => '支付关闭',
            'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_CLOSED] ?? '关闭'),
        ]);
        $this->pushTimelineEvent($events, $sortOrder, 'pay_order', (string) $payOrder->pay_no, 'failed', $payOrder->failed_at, [
            'label' => '支付失败',
            'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_FAILED] ?? '失败'),
            'description' => (string) ($payOrder->channel_error_msg ?? ''),
        ]);
        $this->pushTimelineEvent($events, $sortOrder, 'pay_order', (string) $payOrder->pay_no, 'timeout', $payOrder->timeout_at, [
            'label' => '支付超时',
            'status_text' => (string) ($statusTextMap[TradeConstant::ORDER_STATUS_TIMEOUT] ?? '超时'),
        ]);

        foreach ($callbackLogs as $callback) {
            $processStatus = (int) ($callback['process_status'] ?? 0);
            $verifyStatus = (int) ($callback['verify_status'] ?? 0);
            $eventStatus = $processStatus === 1 && $verifyStatus === 1 ? 'success' : ($processStatus === 2 || $verifyStatus === 2 ? 'failed' : 'processing');
            $this->pushTimelineEvent($events, $sortOrder, 'pay_callback', (string) ($callback['pay_no'] ?? $payOrder->pay_no), $eventStatus, $callback['created_at_text'] ?? null, [
                'label' => '收到上游回调',
                'status_text' => (string) ($callback['process_status_text'] ?? '回调'),
                'description' => trim((string) ($callback['verify_status_text'] ?? '')) !== ''
                    ? '验签：' . (string) $callback['verify_status_text'] . '，处理：' . (string) ($callback['process_status_text'] ?? '')
                    : '',
                'payload' => [
                    'callback_type_text' => (string) ($callback['callback_type_text'] ?? ''),
                    'request_hash' => (string) ($callback['request_hash'] ?? ''),
                ],
            ]);
        }

        foreach ($channelQueryLogs as $queryLog) {
            $processStatus = (int) ($queryLog['process_status'] ?? 0);
            $eventStatus = $processStatus === NotifyConstant::PROCESS_STATUS_SUCCESS
                ? 'success'
                : ($processStatus === NotifyConstant::PROCESS_STATUS_FAILED ? 'failed' : 'processing');
            $payload = (array) ($queryLog['raw_payload'] ?? []);
            $message = trim((string) ($payload['message'] ?? $queryLog['last_error'] ?? ''));
            $at = (string) ($queryLog['updated_at_text'] ?? '');
            if ($at === '' || $at === '—') {
                $at = (string) ($queryLog['created_at_text'] ?? '');
            }
            $this->pushTimelineEvent($events, $sortOrder, 'channel_query', (string) ($queryLog['notify_no'] ?? ''), $eventStatus, $at, [
                'label' => '主动查单',
                'status_text' => (string) ($queryLog['process_status_text'] ?? '查单'),
                'description' => $message !== '' ? $message : '查单状态 ' . (string) ($payload['status'] ?? ''),
                'payload' => [
                    'source' => (string) ($payload['source'] ?? ''),
                    'status' => (string) ($payload['status'] ?? ''),
                    'raw_status' => (string) ($payload['raw_status'] ?? ''),
                    'channel_status' => (string) ($payload['channel_status'] ?? ''),
                    'retry_count' => (int) ($queryLog['retry_count'] ?? 0),
                ],
            ]);
        }

        foreach ($notifyTasks as $task) {
            $taskStatus = (int) ($task['status'] ?? 0);
            $eventStatus = $taskStatus === 1 ? 'success' : ($taskStatus === 2 ? 'failed' : 'processing');
            $createdAt = (string) ($task['created_at_text'] ?? '');
            $lastNotifyAt = (string) ($task['last_notify_at_text'] ?? '');
            $this->pushTimelineEvent($events, $sortOrder, 'merchant_notify', (string) ($task['notify_no'] ?? ''), 'created', $createdAt, [
                'label' => '商户通知任务创建',
                'status_text' => (string) ($task['event_type_text'] ?? '通知'),
                'description' => (string) ($task['notify_url'] ?? ''),
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'merchant_notify', (string) ($task['notify_no'] ?? ''), $eventStatus, $lastNotifyAt, [
                'label' => '商户通知投递',
                'status_text' => (string) ($task['status_text'] ?? '未知'),
                'description' => (string) ($task['last_response'] ?? ''),
                'payload' => [
                    'retry_count' => (int) ($task['retry_count'] ?? 0),
                    'next_retry_at_text' => (string) ($task['next_retry_at_text'] ?? ''),
                ],
            ]);
        }

        foreach ($refundOrders as $refundOrder) {
            $refundNo = (string) $refundOrder->refund_no;
            $refundStatusMap = TradeConstant::refundStatusMap();
            $this->pushTimelineEvent($events, $sortOrder, 'refund_order', $refundNo, 'created', $refundOrder->request_at ?? $refundOrder->created_at, [
                'label' => '退款单创建',
                'status_text' => '创建',
                'description' => '退款金额 ' . $this->formatAmount((int) ($refundOrder->refund_amount ?? 0)) . ' 元',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'refund_order', $refundNo, 'processing', $refundOrder->processing_at, [
                'label' => '退款处理中',
                'status_text' => (string) ($refundStatusMap[TradeConstant::REFUND_STATUS_PROCESSING] ?? '处理中'),
                'description' => '重试次数 ' . (int) ($refundOrder->retry_count ?? 0),
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'refund_order', $refundNo, 'success', $refundOrder->succeeded_at, [
                'label' => '退款成功',
                'status_text' => (string) ($refundStatusMap[TradeConstant::REFUND_STATUS_SUCCESS] ?? '成功'),
                'description' => trim((string) ($refundOrder->channel_refund_no ?? '')) !== '' ? '通道退款号 ' . (string) $refundOrder->channel_refund_no : '',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'refund_order', $refundNo, 'failed', $refundOrder->failed_at, [
                'label' => '退款失败',
                'status_text' => (string) ($refundStatusMap[TradeConstant::REFUND_STATUS_FAILED] ?? '失败'),
                'description' => (string) ($refundOrder->last_error ?? ''),
            ]);
        }

        foreach ($settlementOrders as $settlementOrder) {
            $settleNo = (string) $settlementOrder->settle_no;
            $settlementStatusMap = TradeConstant::settlementStatusMap();
            $this->pushTimelineEvent($events, $sortOrder, 'settlement_order', $settleNo, 'created', $settlementOrder->generated_at ?? $settlementOrder->created_at, [
                'label' => '清算单生成',
                'status_text' => '生成',
                'description' => '净额 ' . $this->formatAmount((int) ($settlementOrder->net_amount ?? 0)) . ' 元',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'settlement_order', $settleNo, 'success', $settlementOrder->accounted_at, [
                'label' => '清算入账',
                'status_text' => '入账',
                'description' => '入账金额 ' . $this->formatAmount((int) ($settlementOrder->accounted_amount ?? 0)) . ' 元',
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'settlement_order', $settleNo, 'success', $settlementOrder->completed_at, [
                'label' => '清算完成',
                'status_text' => (string) ($settlementStatusMap[TradeConstant::SETTLEMENT_STATUS_SETTLED] ?? '已清算'),
            ]);
            $this->pushTimelineEvent($events, $sortOrder, 'settlement_order', $settleNo, 'failed', $settlementOrder->failed_at, [
                'label' => '清算冲正',
                'status_text' => (string) ($settlementStatusMap[TradeConstant::SETTLEMENT_STATUS_REVERSED] ?? '已冲正'),
                'description' => (string) ($settlementOrder->fail_reason ?? ''),
            ]);
        }

        foreach ($accountLedgers as $ledger) {
            $this->pushTimelineEvent($events, $sortOrder, 'account_ledger', (string) $ledger->ledger_no, 'recorded', $ledger->created_at, [
                'label' => '资金流水入账',
                'status_text' => (string) (LedgerConstant::eventTypeMap()[(int) $ledger->event_type] ?? '流水'),
                'description' => sprintf(
                    '%s %s 元，%s',
                    (string) (LedgerConstant::directionMap()[(int) $ledger->direction] ?? '变动'),
                    $this->formatAmount((int) ($ledger->amount ?? 0)),
                    (string) (LedgerConstant::bizTypeMap()[(int) $ledger->biz_type] ?? '')
                ),
            ]);
        }

        foreach ($operationLogs as $operation) {
            $resultStatus = strtolower((string) ($operation['result_status'] ?? ''));
            $eventStatus = in_array($resultStatus, ['success', 'closed'], true)
                ? 'success'
                : (in_array($resultStatus, ['failed', 'error'], true) ? 'failed' : 'processing');
            $this->pushTimelineEvent($events, $sortOrder, 'admin_operation', (string) ($operation['action'] ?? ''), $eventStatus, $operation['created_at_text'] ?? null, [
                'label' => (string) ($operation['action_text'] ?? '后台操作'),
                'status_text' => (string) ($operation['result_status'] ?? ''),
                'description' => trim((string) ($operation['reason'] ?? '')) !== ''
                    ? (string) $operation['reason']
                    : (string) ($operation['result_message'] ?? ''),
                'payload' => [
                    'admin_id' => (int) ($operation['admin_id'] ?? 0),
                    'result_message' => (string) ($operation['result_message'] ?? ''),
                ],
            ]);
        }

        usort($events, static function (array $left, array $right): int {
            $cmp = strcmp((string) ($left['at'] ?? ''), (string) ($right['at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($left['_sort_order'] ?? 0)) <=> ((int) ($right['_sort_order'] ?? 0));
        });

        foreach ($events as &$event) {
            unset($event['_sort_order']);
        }
        unset($event);

        return array_values($events);
    }

    /**
     * 格式化支付相关资金流水。
     *
     * @param array<string, mixed> $row 原始流水数据
     * @return array<string, mixed>
     */
    public function formatLedgerRow(array $row): array
    {
        $row['biz_type_text'] = $this->textFromMap((int) ($row['biz_type'] ?? -1), LedgerConstant::bizTypeMap());
        $row['event_type_text'] = $this->textFromMap((int) ($row['event_type'] ?? -1), LedgerConstant::eventTypeMap());
        $row['direction_text'] = $this->textFromMap((int) ($row['direction'] ?? -1), LedgerConstant::directionMap());
        $row['amount_text'] = $this->formatAmount((int) ($row['amount'] ?? 0));
        $row['available_before_text'] = $this->formatAmount((int) ($row['available_before'] ?? 0));
        $row['available_after_text'] = $this->formatAmount((int) ($row['available_after'] ?? 0));
        $row['frozen_before_text'] = $this->formatAmount((int) ($row['frozen_before'] ?? 0));
        $row['frozen_after_text'] = $this->formatAmount((int) ($row['frozen_after'] ?? 0));
        $row['created_at_text'] = $this->formatDateTime($row['created_at'] ?? null, '—');

        return $row;
    }

    /**
     * 追加时间线事件。
     *
     * @param array<int, array<string, mixed>> $events 事件列表
     * @param int $sortOrder 排序序号
     * @param string $type 事件类型
     * @param string $sourceNo 事件来源单号
     * @param string $status 事件状态
     * @param \DateTimeInterface|int|string|float|null $at 事件时间
     * @param array<string, mixed> $payload 事件载荷
     * @return void
     */
    private function pushTimelineEvent(array &$events, int &$sortOrder, string $type, string $sourceNo, string $status, \DateTimeInterface|int|string|float|null $at, array $payload): void
    {
        $atText = $this->formatDateTime($at, '');
        if ($atText === '' || $atText === '—') {
            return;
        }

        $events[] = [
            'type' => $type,
            'source_no' => $sourceNo,
            'status' => $status,
            'status_text' => (string) ($payload['status_text'] ?? ''),
            'label' => (string) ($payload['label'] ?? ''),
            'at' => $atText,
            'description' => (string) ($payload['description'] ?? ''),
            'payload' => (array) ($payload['payload'] ?? []),
            '_sort_order' => $sortOrder++,
        ];
    }
}
