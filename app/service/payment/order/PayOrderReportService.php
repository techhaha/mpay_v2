<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
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
        $row['fee_status_text'] = $this->textFromMap((int) ($row['fee_status'] ?? -1), TradeConstant::feeStatusMap());
        $row['settlement_status_text'] = $this->textFromMap((int) ($row['settlement_status'] ?? -1), TradeConstant::settlementStatusMap());
        $row['callback_status_text'] = $this->textFromMap((int) ($row['callback_status'] ?? -1), NotifyConstant::processStatusMap());
        $row['channel_type_text'] = $this->textFromMap((int) ($row['channel_type'] ?? -1), RouteConstant::channelTypeMap());
        $row['channel_mode_text'] = $this->textFromMap((int) ($row['channel_mode'] ?? -1), RouteConstant::channelModeMap());

        $row['pay_amount_text'] = $this->formatAmount((int) ($row['pay_amount'] ?? 0));
        $row['fee_estimated_amount_text'] = $this->formatAmount((int) ($row['fee_estimated_amount'] ?? 0));
        $row['fee_actual_amount_text'] = $this->formatAmount((int) ($row['fee_actual_amount'] ?? 0));
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
        $extJson = (array) ($payOrder->ext_json ?? []);

        // 只保留真实发生过的节点，未触发的状态直接过滤掉，避免时间线里出现空占位。
        return array_values(array_filter([
            [
                'status' => 'created',
                'at' => $this->formatDateTime($payOrder->request_at ?? $payOrder->created_at ?? null, '—'),
            ],
            $payOrder->paid_at ? [
                'status' => 'success',
                'at' => $this->formatDateTime($payOrder->paid_at, '—'),
            ] : null,
            $payOrder->closed_at ? [
                'status' => 'closed',
                'at' => $this->formatDateTime($payOrder->closed_at, '—'),
                // 关闭原因优先取扩展信息里的记录，便于展示人工关单或自动关单原因。
                'reason' => (string) ($extJson['close_reason'] ?? ''),
            ] : null,
            $payOrder->failed_at ? [
                'status' => 'failed',
                'at' => $this->formatDateTime($payOrder->failed_at, '—'),
                // 失败原因先看渠道返回，再回落到扩展信息里保存的统一原因字段。
                'reason' => (string) ($payOrder->channel_error_msg ?: ($extJson['reason'] ?? '')),
            ] : null,
            $payOrder->timeout_at ? [
                'status' => 'timeout',
                'at' => $this->formatDateTime($payOrder->timeout_at, '—'),
                'reason' => (string) ($extJson['timeout_reason'] ?? ''),
            ] : null,
        ]));
    }
}


