<?php

namespace app\service\ops\dashboard;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\repository\ops\dashboard\AdminDashboardRepository;

/**
 * 管理后台运营首页聚合服务。
 *
 * 负责运营口径编排和展示字段格式化，数据库查询由仓库层承载。
 */
class AdminDashboardService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param AdminDashboardRepository $adminDashboardRepository 运营首页查询仓库
     * @return void
     */
    public function __construct(
        protected AdminDashboardRepository $adminDashboardRepository
    ) {
    }

    /**
     * 获取运营首页总览。
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $today = date('Y-m-d');
        $todayStart = $today . ' 00:00:00';
        $todayEnd = date('Y-m-d 00:00:00', strtotime($todayStart . ' +1 day'));

        $todayPay = $this->adminDashboardRepository->todayPaySummary($todayStart, $todayEnd);
        $todayRefund = $this->adminDashboardRepository->todayRefundSummary($todayStart, $todayEnd);
        $pendingSettlement = $this->adminDashboardRepository->pendingSettlementSummary();
        $notifySummary = $this->adminDashboardRepository->notifySummary();
        $callbackSummary = $this->adminDashboardRepository->callbackSummary($todayStart, $todayEnd);
        $channelSummary = $this->adminDashboardRepository->channelSummary($today);

        return [
            'generated_at' => $this->formatDateTime($this->now()),
            'stat_date' => $today,
            'metrics' => $this->metrics($todayPay, $todayRefund, $pendingSettlement, $notifySummary, $callbackSummary, $channelSummary),
            'health' => $this->health($todayPay, $notifySummary, $callbackSummary, $channelSummary),
            'tasks' => $this->tasks($todayPay, $pendingSettlement, $notifySummary, $callbackSummary, $channelSummary),
            'alerts' => $this->alerts($todayStart, $todayEnd, $today),
            'pay_trend' => $this->payTrend(7),
            'pay_type_share' => $this->payTypeShare($todayStart, $todayEnd),
            'abnormal_channels' => $this->abnormalChannels($today),
            'recent_orders' => $this->recentOrders(),
        ];
    }

    /**
     * 构建核心指标。
     *
     * @param array<string, int> $pay 支付摘要
     * @param array<string, int> $refund 退款摘要
     * @param array<string, int> $settlement 清算摘要
     * @param array<string, int> $notify 通知摘要
     * @param array<string, int> $callback 回调摘要
     * @param array<string, int> $channel 通道摘要
     * @return array<int, array<string, mixed>>
     */
    private function metrics(array $pay, array $refund, array $settlement, array $notify, array $callback, array $channel): array
    {
        $successRateBp = $pay['total_count'] > 0 ? (int) floor($pay['success_count'] * 10000 / $pay['total_count']) : 0;
        $refundRateBp = $pay['pay_amount'] > 0 ? (int) floor($refund['refund_amount'] * 10000 / $pay['pay_amount']) : 0;

        return [
            ['key' => 'pay_amount', 'label' => '今日交易额', 'value' => $pay['pay_amount'], 'value_text' => $this->formatAmount($pay['pay_amount']), 'tone' => 'primary'],
            ['key' => 'success_rate', 'label' => '支付成功率', 'value' => $successRateBp, 'value_text' => $this->formatRate($successRateBp), 'tone' => $successRateBp >= 9500 || $pay['total_count'] === 0 ? 'success' : 'warning'],
            ['key' => 'order_count', 'label' => '今日订单数', 'value' => $pay['total_count'], 'value_text' => (string) $pay['total_count'], 'tone' => 'primary'],
            ['key' => 'refund_rate', 'label' => '退款率', 'value' => $refundRateBp, 'value_text' => $this->formatRate($refundRateBp), 'tone' => $refundRateBp > 1000 ? 'warning' : 'success'],
            ['key' => 'pending_settlement', 'label' => '待清算金额', 'value' => $settlement['pending_amount'], 'value_text' => $this->formatAmount($settlement['pending_amount']), 'tone' => $settlement['pending_count'] > 0 ? 'warning' : 'success'],
            ['key' => 'notify_failed', 'label' => '通知失败数', 'value' => $notify['failed_count'], 'value_text' => (string) $notify['failed_count'], 'tone' => $notify['failed_count'] > 0 ? 'danger' : 'success'],
            ['key' => 'callback_failed', 'label' => '回调失败数', 'value' => $callback['failed_count'], 'value_text' => (string) $callback['failed_count'], 'tone' => $callback['failed_count'] > 0 ? 'danger' : 'success'],
            ['key' => 'channel_abnormal', 'label' => '通道异常数', 'value' => $channel['abnormal_count'], 'value_text' => (string) $channel['abnormal_count'], 'tone' => $channel['abnormal_count'] > 0 ? 'danger' : 'success'],
        ];
    }

    /**
     * 构建健康度摘要。
     *
     * @param array<string, int> $pay 支付摘要
     * @param array<string, int> $notify 通知摘要
     * @param array<string, int> $callback 回调摘要
     * @param array<string, int> $channel 通道摘要
     * @return array<string, mixed>
     */
    private function health(array $pay, array $notify, array $callback, array $channel): array
    {
        $successRate = $pay['total_count'] > 0 ? $pay['success_count'] * 100 / $pay['total_count'] : 100;
        $score = 100;
        $score -= max(0, (int) ceil((95 - $successRate) * 2));
        $score -= min(20, $notify['failed_count'] * 2);
        $score -= min(15, $callback['failed_count'] * 3);
        $score -= min(20, $channel['abnormal_count'] * 5);
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'score_text' => number_format($score, 0),
            'status_text' => $score >= 90 ? '稳定' : ($score >= 70 ? '关注' : '异常'),
            'enabled_channel_count' => $channel['enabled_count'],
            'total_channel_count' => $channel['total_count'],
            'enabled_channel_text' => $channel['enabled_count'] . ' / ' . $channel['total_count'],
        ];
    }

    /**
     * 构建待处理任务。
     *
     * @param array<string, int> $pay 支付摘要
     * @param array<string, int> $settlement 清算摘要
     * @param array<string, int> $notify 通知摘要
     * @param array<string, int> $callback 回调摘要
     * @param array<string, int> $channel 通道摘要
     * @return array<int, array<string, mixed>>
     */
    private function tasks(array $pay, array $settlement, array $notify, array $callback, array $channel): array
    {
        return [
            ['key' => 'notify_failed', 'title' => '失败通知', 'count' => $notify['failed_count'], 'description' => '商户通知投递失败，需要重试或排查商户响应', 'path' => '/transaction/merchant-notify-task', 'query' => ['status' => NotifyConstant::TASK_STATUS_FAILED], 'tone' => $notify['failed_count'] > 0 ? 'danger' : 'success'],
            ['key' => 'settlement_pending', 'title' => '待清算', 'count' => $settlement['pending_count'], 'description' => '待入账或待人工处理的清算单', 'path' => '/transaction/settlement-order', 'query' => ['status' => TradeConstant::SETTLEMENT_STATUS_PENDING], 'tone' => $settlement['pending_count'] > 0 ? 'warning' : 'success'],
            ['key' => 'abnormal_order', 'title' => '异常订单', 'count' => $pay['abnormal_count'], 'description' => '今日失败或超时支付单', 'path' => '/transaction/pay-order', 'query' => [], 'tone' => $pay['abnormal_count'] > 0 ? 'danger' : 'success'],
            ['key' => 'callback_failed', 'title' => '回调失败', 'count' => $callback['failed_count'], 'description' => '今日上游回调验签或处理失败', 'path' => '/transaction/callback-log', 'query' => ['process_status' => NotifyConstant::PROCESS_STATUS_FAILED], 'tone' => $callback['failed_count'] > 0 ? 'danger' : 'success'],
            ['key' => 'channel_abnormal', 'title' => '通道健康告警', 'count' => $channel['abnormal_count'], 'description' => '今日通道失败、耗时或限额异常', 'path' => '/channel/channel-monitor', 'query' => [], 'tone' => $channel['abnormal_count'] > 0 ? 'danger' : 'success'],
        ];
    }

    /**
     * 构建运维告警。
     *
     * @param string $todayStart 今日开始时间
     * @param string $todayEnd 今日结束时间
     * @param string $today 今日日期
     * @return array<int, array<string, mixed>>
     */
    private function alerts(string $todayStart, string $todayEnd, string $today): array
    {
        $retryLimit = max(1, (int) sys_config('pay_notify_retry_limit', 3));
        $notifyExceededCount = $this->adminDashboardRepository->notifyRetryExceededCount($retryLimit);
        $callbackSummary = $this->adminDashboardRepository->callbackSummary($todayStart, $todayEnd);
        $routeMissingCount = $this->adminDashboardRepository->routeMissingGroupCount();
        $disabledChannelCount = $this->adminDashboardRepository->disabledChannelCount();
        $abnormalChannelCount = $this->adminDashboardRepository->channelSummary($today)['abnormal_count'];
        $channelAlertCount = $disabledChannelCount + $abnormalChannelCount;

        return [
            [
                'key' => 'notify_retry_exceeded',
                'title' => '通知重试超限',
                'count' => $notifyExceededCount,
                'description' => '失败通知已达到重试上限，可进入通知任务人工补发或排查商户响应',
                'path' => '/transaction/merchant-notify-task',
                'query' => ['status' => NotifyConstant::TASK_STATUS_FAILED],
                'tone' => $notifyExceededCount > 0 ? 'danger' : 'success',
                'items' => $this->notifyAlertItems($retryLimit),
            ],
            [
                'key' => 'callback_failed',
                'title' => '回调失败',
                'count' => $callbackSummary['failed_count'],
                'description' => '今日上游回调验签或业务处理失败，需要核对签名、幂等和订单状态',
                'path' => '/transaction/callback-log',
                'query' => ['process_status' => NotifyConstant::PROCESS_STATUS_FAILED],
                'tone' => $callbackSummary['failed_count'] > 0 ? 'danger' : 'success',
                'items' => $this->callbackAlertItems($todayStart, $todayEnd),
            ],
            [
                'key' => 'route_missing',
                'title' => '路由无命中风险',
                'count' => $routeMissingCount,
                'description' => '启用商户所在分组没有启用路由绑定，正式下单可能无法完成选路',
                'path' => '/route/route-compile',
                'query' => [],
                'tone' => $routeMissingCount > 0 ? 'warning' : 'success',
                'items' => $this->routeAlertItems(),
            ],
            [
                'key' => 'channel_unavailable',
                'title' => '通道不可用/异常',
                'count' => $channelAlertCount,
                'description' => '包含禁用通道以及今日健康分低、失败、耗时高或接近限额的通道',
                'path' => '/channel/channel-monitor',
                'query' => [],
                'tone' => $channelAlertCount > 0 ? 'danger' : 'success',
                'items' => $this->channelAlertItems($today),
            ],
        ];
    }

    /**
     * 通知超限告警明细。
     *
     * @param int $retryLimit 重试上限
     * @return array<int, array<string, string>>
     */
    private function notifyAlertItems(int $retryLimit): array
    {
        return $this->adminDashboardRepository->notifyRetryExceededRows($retryLimit)
            ->map(function ($row) {
                $eventMap = NotifyConstant::eventTypeMap();
                $eventType = (string) $row->event_type;

                return [
                    'title' => (string) ($row->merchant_name ?: $row->notify_no),
                    'meta' => (string) ($eventMap[$eventType] ?? $eventType)
                        . ' / 重试 ' . (int) $row->retry_count . ' 次',
                    'extra' => $this->formatDateTime($row->last_notify_at ?? null, '未投递'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 回调失败告警明细。
     *
     * @param string $todayStart 今日开始时间
     * @param string $todayEnd 今日结束时间
     * @return array<int, array<string, string>>
     */
    private function callbackAlertItems(string $todayStart, string $todayEnd): array
    {
        return $this->adminDashboardRepository->failedCallbackRows($todayStart, $todayEnd)
            ->map(function ($row) {
                return [
                    'title' => (string) ($row->pay_no ?: '未知支付单'),
                    'meta' => (string) ($row->channel_name ?: '未知通道')
                        . ' / 验签' . $this->textFromMap((int) $row->verify_status, NotifyConstant::verifyStatusMap())
                        . ' / 处理' . $this->textFromMap((int) $row->process_status, NotifyConstant::processStatusMap()),
                    'extra' => $this->formatDateTime($row->created_at ?? null, '—'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 路由配置风险告警明细。
     *
     * @return array<int, array<string, string>>
     */
    private function routeAlertItems(): array
    {
        return $this->adminDashboardRepository->routeMissingGroupRows()
            ->map(function ($row) {
                return [
                    'title' => (string) ($row->group_name ?: '未命名分组'),
                    'meta' => '启用商户 ' . (int) $row->merchant_count . ' 个',
                    'extra' => '缺少启用路由绑定',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 通道不可用或异常告警明细。
     *
     * @param string $today 今日日期
     * @return array<int, array<string, string>>
     */
    private function channelAlertItems(string $today): array
    {
        $items = $this->adminDashboardRepository->disabledChannelRows(3)
            ->map(function ($row) {
                return [
                    'title' => (string) ($row->name ?: '未知通道'),
                    'meta' => (string) ($row->pay_type_name ?: '未知支付方式') . ' / ' . (string) ($row->plugin_code ?? ''),
                    'extra' => '已禁用',
                ];
            })
            ->values()
            ->all();

        $abnormalItems = $this->adminDashboardRepository->abnormalChannelRows($today)
            ->take(3)
            ->map(function ($row) {
                return [
                    'title' => (string) ($row->channel_name ?: '未知通道'),
                    'meta' => '健康分 ' . (int) $row->health_score . ' / 失败 ' . (int) $row->pay_fail_count,
                    'extra' => $this->formatLatency((int) $row->avg_latency_ms),
                ];
            })
            ->values()
            ->all();

        return array_slice(array_merge($items, $abnormalItems), 0, 5);
    }

    /**
     * 近 N 日支付趋势。
     *
     * @param int $days 天数
     * @return array<int, array<string, mixed>>
     */
    private function payTrend(int $days): array
    {
        $days = max(1, min(30, $days));
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = $this->adminDashboardRepository->payTrendRows($startDate);

        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime($startDate . ' +' . $i . ' days'));
            $row = $rows->get($date);
            $amount = (int) ($row->pay_amount ?? 0);
            $trend[] = [
                'date' => $date,
                'date_text' => date('m-d', strtotime($date)),
                'total_count' => (int) ($row->total_count ?? 0),
                'success_count' => (int) ($row->success_count ?? 0),
                'pay_amount' => $amount,
                'pay_amount_text' => $this->formatAmount($amount),
            ];
        }

        return $trend;
    }

    /**
     * 今日支付方式占比。
     *
     * @param string $start 开始时间
     * @param string $end 结束时间
     * @return array<int, array<string, mixed>>
     */
    private function payTypeShare(string $start, string $end): array
    {
        $rows = $this->adminDashboardRepository->payTypeShareRows($start, $end);
        $totalAmount = (int) $rows->sum('amount_value');

        return $rows->map(function ($row) use ($totalAmount) {
            $amount = (int) ($row->amount_value ?? 0);
            $rateBp = $totalAmount > 0 ? (int) floor($amount * 10000 / $totalAmount) : 0;

            return [
                'name' => (string) ($row->name ?? '未知方式'),
                'count' => (int) ($row->count_value ?? 0),
                'amount' => $amount,
                'amount_text' => $this->formatAmount($amount),
                'rate_bp' => $rateBp,
                'rate_text' => $this->formatRate($rateBp),
            ];
        })->values()->all();
    }

    /**
     * 最近异常通道。
     *
     * @param string $statDate 统计日期
     * @return array<int, array<string, mixed>>
     */
    private function abnormalChannels(string $statDate): array
    {
        return $this->adminDashboardRepository->abnormalChannelRows($statDate)
            ->map(function ($row) {
                return [
                    'channel_id' => (int) $row->channel_id,
                    'channel_name' => (string) ($row->channel_name ?: '未知通道'),
                    'plugin_code' => (string) ($row->plugin_code ?? ''),
                    'pay_success_count' => (int) $row->pay_success_count,
                    'pay_fail_count' => (int) $row->pay_fail_count,
                    'pay_amount' => (int) $row->pay_amount,
                    'pay_amount_text' => $this->formatAmount((int) $row->pay_amount),
                    'success_rate_text' => $this->formatRate((int) $row->success_rate_bp),
                    'health_score' => (int) $row->health_score,
                    'avg_latency_ms_text' => $this->formatLatency((int) $row->avg_latency_ms),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 最近支付订单。
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return $this->adminDashboardRepository->recentOrderRows()
            ->map(function ($row) {
                return [
                    'pay_no' => (string) $row->pay_no,
                    'biz_no' => (string) $row->biz_no,
                    'merchant_name' => (string) ($row->merchant_name ?: '未知商户'),
                    'channel_name' => (string) ($row->channel_name ?: '未知通道'),
                    'pay_type_name' => (string) ($row->pay_type_name ?: '未知方式'),
                    'pay_amount' => (int) $row->pay_amount,
                    'pay_amount_text' => $this->formatAmount((int) $row->pay_amount),
                    'status' => (int) $row->status,
                    'status_text' => $this->textFromMap((int) $row->status, TradeConstant::orderStatusMap()),
                    'channel_error_msg' => (string) ($row->channel_error_msg ?? ''),
                    'created_at_text' => $this->formatDateTime($row->created_at ?? null, '—'),
                ];
            })
            ->values()
            ->all();
    }
}
