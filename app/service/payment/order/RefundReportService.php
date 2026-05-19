<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\LedgerConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;

/**
 * 退款单结果组装服务。
 *
 * 负责退款列表、详情页和资金流水的展示字段格式化。
 */
class RefundReportService extends BaseService
{
    /**
     * 格式化退款订单行，统一输出前端展示字段。
     *
     * @param array<string, mixed> $row 原始查询行
     * @return array<string, mixed> 格式化后的退款单行
     */
    public function formatRefundOrderRow(array $row): array
    {
        $extJson = (array) ($row['ext_json'] ?? []);
        $adminAction = (array) ($extJson['admin_action'] ?? []);
        $source = (string) ($adminAction['type'] ?? '');

        $row['merchant_group_name'] = trim((string) ($row['merchant_group_name'] ?? '')) ?: '未分组';
        $row['merchant_name'] = trim((string) ($row['merchant_name'] ?? '')) ?: '未知商户';
        $row['merchant_short_name'] = trim((string) ($row['merchant_short_name'] ?? ''));
        $row['pay_type_name'] = trim((string) ($row['pay_type_name'] ?? '')) ?: '未知方式';
        $row['channel_name'] = trim((string) ($row['channel_name'] ?? '')) ?: '未知通道';

        $row['status_text'] = $this->textFromMap((int) ($row['status'] ?? -1), TradeConstant::refundStatusMap());
        $row['pay_status_text'] = $this->textFromMap((int) ($row['pay_status'] ?? -1), TradeConstant::orderStatusMap());
        $row['channel_type_text'] = $this->textFromMap((int) ($row['channel_type'] ?? -1), RouteConstant::channelTypeMap());
        $row['channel_mode_text'] = $this->textFromMap((int) ($row['channel_mode'] ?? -1), RouteConstant::channelModeMap());

        $row['refund_amount_text'] = $this->formatAmount((int) ($row['refund_amount'] ?? 0));
        $row['fee_reverse_amount_text'] = $this->formatAmount((int) ($row['fee_reverse_amount'] ?? 0));
        $row['pay_order_amount_text'] = $this->formatAmount((int) ($row['pay_order_amount'] ?? 0));
        $row['pay_service_fee_amount_text'] = $this->formatAmount((int) ($row['pay_service_fee_amount'] ?? 0));
        $row['biz_order_amount_text'] = $this->formatAmount((int) ($row['biz_order_amount'] ?? 0));
        $row['biz_paid_amount_text'] = $this->formatAmount((int) ($row['biz_paid_amount'] ?? 0));
        $row['biz_refund_amount_text'] = $this->formatAmount((int) ($row['biz_refund_amount'] ?? 0));

        $row['request_at_text'] = $this->formatDateTime($row['request_at'] ?? null, '—');
        $row['processing_at_text'] = $this->formatDateTime($row['processing_at'] ?? null, '—');
        $row['succeeded_at_text'] = $this->formatDateTime($row['succeeded_at'] ?? null, '—');
        $row['failed_at_text'] = $this->formatDateTime($row['failed_at'] ?? null, '—');
        $row['refund_source'] = $source !== '' ? $source : 'merchant_api';
        $row['refund_source_text'] = $this->refundSourceText($row['refund_source']);
        $row['upstream_status_text'] = $this->upstreamStatusText($row);
        $row['retry_history_text'] = $this->retryHistoryText($row, $extJson);

        return $row;
    }

    /**
     * 构造退款时间线。
     *
     * 依次输出创建、处理中、成功和失败节点，便于前端直接展示进度。
     *
     * @param object|null $refundOrder 退款订单或查询行
     * @return array<int, array<string, mixed>> 退款时间线
     */
    public function buildRefundTimeline(object|null $refundOrder): array
    {
        $extJson = (array) ($refundOrder->ext_json ?? []);

        // 退款时间线同样只展示已经发生的节点，并尽量用扩展信息补全原因字段。
        return array_values(array_filter([
            [
                'status' => 'created',
                'label' => '退款单创建',
                'at' => $this->formatDateTime($refundOrder->request_at ?? $refundOrder->created_at ?? null, '—'),
            ],
            $refundOrder->processing_at ? [
                'status' => 'processing',
                'label' => '退款处理中',
                'at' => $this->formatDateTime($refundOrder->processing_at, '—'),
                'retry_count' => (int) ($refundOrder->retry_count ?? 0),
                // 处理中原因优先按重试原因、处理中原因、最后错误的顺序回退。
                'reason' => (string) ($extJson['retry_reason'] ?? $extJson['processing_reason'] ?? $refundOrder->last_error ?? ''),
            ] : null,
            $refundOrder->succeeded_at ? [
                'status' => 'success',
                'label' => '退款成功',
                'at' => $this->formatDateTime($refundOrder->succeeded_at, '—'),
            ] : null,
            $refundOrder->failed_at ? [
                'status' => 'failed',
                'label' => '退款失败',
                'at' => $this->formatDateTime($refundOrder->failed_at, '—'),
                // 失败原因先看最后错误，再回退到扩展信息和退款单原始原因。
                'reason' => (string) ($refundOrder->last_error ?: ($extJson['fail_reason'] ?? $refundOrder->reason ?? '')),
            ] : null,
        ]));
    }

    /**
     * 格式化退款相关资金流水。
     *
     * @param array<string, mixed> $row 原始查询行
     * @return array<string, mixed> 格式化后的流水行
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
     * 退款来源文案。
     *
     * @param string $source 来源码
     * @return string 来源文案
     */
    private function refundSourceText(string $source): string
    {
        return [
            'api_refund' => '后台 API 退款',
            'manual_refund' => '后台手动退款',
            'merchant_api' => '商户/API 发起',
        ][$source] ?? $source;
    }

    /**
     * 上游退款状态文案。
     *
     * @param array<string, mixed> $row 退款单行
     * @return string 状态文案
     */
    private function upstreamStatusText(array $row): string
    {
        $source = (string) ($row['refund_source'] ?? '');
        if ($source === 'manual_refund') {
            return '后台手动登记，无上游退款请求';
        }

        if (trim((string) ($row['channel_refund_no'] ?? '')) !== '') {
            return '上游已返回退款号';
        }

        if ((int) ($row['status'] ?? 0) === TradeConstant::REFUND_STATUS_PROCESSING) {
            return trim((string) ($row['channel_request_no'] ?? '')) !== '' ? '已请求上游，等待结果' : '等待投递上游';
        }

        if ((int) ($row['status'] ?? 0) === TradeConstant::REFUND_STATUS_FAILED) {
            return trim((string) ($row['last_error'] ?? '')) !== '' ? '上游或插件处理失败' : '退款失败';
        }

        if ((int) ($row['status'] ?? 0) === TradeConstant::REFUND_STATUS_SUCCESS) {
            return '退款成功';
        }

        return '未请求上游';
    }

    /**
     * 退款重试摘要。
     *
     * @param array<string, mixed> $row 退款单行
     * @param array<string, mixed> $extJson 扩展信息
     * @return string 重试摘要
     */
    private function retryHistoryText(array $row, array $extJson): string
    {
        $retryCount = (int) ($row['retry_count'] ?? 0);
        $reason = trim((string) ($row['last_error'] ?? $extJson['retry_reason'] ?? $extJson['processing_reason'] ?? ''));
        if ($retryCount <= 0) {
            return $reason !== '' ? '未重试，最近原因：' . $reason : '暂无重试';
        }

        return '已重试 ' . $retryCount . ' 次' . ($reason !== '' ? '，最近原因：' . $reason : '');
    }
}
