<?php

namespace app\repository\ops\dashboard;

use app\common\constant\CommonConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\TradeConstant;
use app\model\admin\ChannelDailyStat;
use app\model\admin\PayCallbackLog;
use app\model\merchant\MerchantGroup;
use app\model\payment\NotifyTask;
use app\model\payment\PaymentChannel;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\model\payment\SettlementOrder;

/**
 * 管理后台运营首页查询仓库。
 *
 * 只封装首页所需的只读聚合查询，不承载展示格式化逻辑。
 */
class AdminDashboardRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct(
        protected PayOrder $payOrder = new PayOrder(),
        protected RefundOrder $refundOrder = new RefundOrder(),
        protected SettlementOrder $settlementOrder = new SettlementOrder(),
        protected NotifyTask $notifyTask = new NotifyTask(),
        protected PayCallbackLog $payCallbackLog = new PayCallbackLog(),
        protected PaymentChannel $paymentChannel = new PaymentChannel(),
        protected MerchantGroup $merchantGroup = new MerchantGroup(),
        protected ChannelDailyStat $channelDailyStat = new ChannelDailyStat()
    ) {
    }

    /**
     * 今日支付摘要。
     *
     * @param string $start 开始时间
     * @param string $end 结束时间
     * @return array<string, int>
     */
    public function todayPaySummary(string $start, string $end): array
    {
        $created = $this->payOrder->newQuery()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS success_count', [TradeConstant::ORDER_STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS abnormal_count', [TradeConstant::ORDER_STATUS_FAILED, TradeConstant::ORDER_STATUS_TIMEOUT])
            ->first();

        $paid = $this->payOrder->newQuery()
            ->where('status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<', $end)
            ->selectRaw('COUNT(*) AS paid_count')
            ->selectRaw('COALESCE(SUM(pay_amount), 0) AS pay_amount')
            ->first();

        return [
            'total_count' => (int) ($created->total_count ?? 0),
            'success_count' => (int) ($created->success_count ?? 0),
            'abnormal_count' => (int) ($created->abnormal_count ?? 0),
            'paid_count' => (int) ($paid->paid_count ?? 0),
            'pay_amount' => (int) ($paid->pay_amount ?? 0),
        ];
    }

    /**
     * 今日退款摘要。
     *
     * @param string $start 开始时间
     * @param string $end 结束时间
     * @return array<string, int>
     */
    public function todayRefundSummary(string $start, string $end): array
    {
        $row = $this->refundOrder->newQuery()
            ->where('status', TradeConstant::REFUND_STATUS_SUCCESS)
            ->where('succeeded_at', '>=', $start)
            ->where('succeeded_at', '<', $end)
            ->selectRaw('COUNT(*) AS refund_count')
            ->selectRaw('COALESCE(SUM(refund_amount), 0) AS refund_amount')
            ->first();

        return [
            'refund_count' => (int) ($row->refund_count ?? 0),
            'refund_amount' => (int) ($row->refund_amount ?? 0),
        ];
    }

    /**
     * 待清算摘要。
     *
     * @return array<string, int>
     */
    public function pendingSettlementSummary(): array
    {
        $row = $this->settlementOrder->newQuery()
            ->where('status', TradeConstant::SETTLEMENT_STATUS_PENDING)
            ->selectRaw('COUNT(*) AS pending_count')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS pending_amount')
            ->first();

        return [
            'pending_count' => (int) ($row->pending_count ?? 0),
            'pending_amount' => (int) ($row->pending_amount ?? 0),
        ];
    }

    /**
     * 商户通知摘要。
     *
     * @return array<string, int>
     */
    public function notifySummary(): array
    {
        $row = $this->notifyTask->newQuery()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS failed_count', [NotifyConstant::TASK_STATUS_FAILED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS pending_count', [NotifyConstant::TASK_STATUS_PENDING])
            ->first();

        return [
            'total_count' => (int) ($row->total_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
        ];
    }

    /**
     * 商户通知重试超限数量。
     *
     * @param int $retryLimit 重试上限
     * @return int
     */
    public function notifyRetryExceededCount(int $retryLimit): int
    {
        return (int) $this->notifyTask->newQuery()
            ->where('status', NotifyConstant::TASK_STATUS_FAILED)
            ->where('retry_count', '>=', $retryLimit)
            ->count();
    }

    /**
     * 商户通知重试超限原始行。
     *
     * @param int $retryLimit 重试上限
     * @param int $limit 返回条数
     * @return \Illuminate\Support\Collection
     */
    public function notifyRetryExceededRows(int $retryLimit, int $limit = 5)
    {
        return $this->notifyTask->newQuery()
            ->from('ma_notify_task as n')
            ->leftJoin('ma_merchant as m', 'n.merchant_id', '=', 'm.id')
            ->where('n.status', NotifyConstant::TASK_STATUS_FAILED)
            ->where('n.retry_count', '>=', $retryLimit)
            ->select([
                'n.notify_no',
                'n.event_type',
                'n.ref_no',
                'n.retry_count',
                'n.next_retry_at',
                'n.last_notify_at',
                'n.last_response',
            ])
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->orderByDesc('n.retry_count')
            ->orderByDesc('n.updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 回调摘要。
     *
     * @param string $start 开始时间
     * @param string $end 结束时间
     * @return array<string, int>
     */
    public function callbackSummary(string $start, string $end): array
    {
        $row = $this->payCallbackLog->newQuery()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN verify_status = ? OR process_status = ? THEN 1 ELSE 0 END), 0) AS failed_count',
                [NotifyConstant::VERIFY_STATUS_FAILED, NotifyConstant::PROCESS_STATUS_FAILED]
            )
            ->first();

        return [
            'total_count' => (int) ($row->total_count ?? 0),
            'failed_count' => (int) ($row->failed_count ?? 0),
        ];
    }

    /**
     * 今日失败回调原始行。
     *
     * @param string $start 开始时间
     * @param string $end 结束时间
     * @param int $limit 返回条数
     * @return \Illuminate\Support\Collection
     */
    public function failedCallbackRows(string $start, string $end, int $limit = 5)
    {
        return $this->payCallbackLog->newQuery()
            ->from('ma_pay_callback_log as l')
            ->leftJoin('ma_payment_channel as c', 'l.channel_id', '=', 'c.id')
            ->where('l.created_at', '>=', $start)
            ->where('l.created_at', '<', $end)
            ->where(function ($builder) {
                $builder->where('l.verify_status', NotifyConstant::VERIFY_STATUS_FAILED)
                    ->orWhere('l.process_status', NotifyConstant::PROCESS_STATUS_FAILED);
            })
            ->select([
                'l.pay_no',
                'l.verify_status',
                'l.process_status',
                'l.created_at',
            ])
            ->selectRaw("COALESCE(c.name, '') AS channel_name")
            ->orderByDesc('l.created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 通道摘要。
     *
     * @param string $statDate 统计日期
     * @return array<string, int>
     */
    public function channelSummary(string $statDate): array
    {
        $channel = $this->paymentChannel->newQuery()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS enabled_count', [CommonConstant::STATUS_ENABLED])
            ->first();

        return [
            'total_count' => (int) ($channel->total_count ?? 0),
            'enabled_count' => (int) ($channel->enabled_count ?? 0),
            'abnormal_count' => (int) $this->abnormalChannelQuery($statDate)->count(),
        ];
    }

    /**
     * 禁用通道数量。
     *
     * @return int
     */
    public function disabledChannelCount(): int
    {
        return (int) $this->paymentChannel->newQuery()
            ->where('status', '<>', CommonConstant::STATUS_ENABLED)
            ->count();
    }

    /**
     * 禁用通道原始行。
     *
     * @param int $limit 返回条数
     * @return \Illuminate\Support\Collection
     */
    public function disabledChannelRows(int $limit = 5)
    {
        return $this->paymentChannel->newQuery()
            ->from('ma_payment_channel as c')
            ->leftJoin('ma_payment_type as t', 'c.pay_type_id', '=', 't.id')
            ->where('c.status', '<>', CommonConstant::STATUS_ENABLED)
            ->select([
                'c.id',
                'c.name',
                'c.plugin_code',
                'c.status',
                'c.updated_at',
            ])
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name")
            ->orderByDesc('c.updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 存在启用商户但没有启用路由绑定的商户分组数量。
     *
     * @return int
     */
    public function routeMissingGroupCount(): int
    {
        return $this->routeMissingGroupBaseQuery()->get()->count();
    }

    /**
     * 存在启用商户但没有启用路由绑定的商户分组原始行。
     *
     * @param int $limit 返回条数
     * @return \Illuminate\Support\Collection
     */
    public function routeMissingGroupRows(int $limit = 5)
    {
        return $this->routeMissingGroupBaseQuery()
            ->orderByDesc('merchant_count')
            ->limit($limit)
            ->get();
    }

    /**
     * 近 N 日支付趋势原始行。
     *
     * @param string $startDate 开始日期
     * @return \Illuminate\Support\Collection
     */
    public function payTrendRows(string $startDate)
    {
        return $this->payOrder->newQuery()
            ->where('created_at', '>=', $startDate . ' 00:00:00')
            ->selectRaw('DATE(created_at) AS stat_date')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS success_count', [TradeConstant::ORDER_STATUS_SUCCESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN pay_amount ELSE 0 END), 0) AS pay_amount', [TradeConstant::ORDER_STATUS_SUCCESS])
            ->groupBy('stat_date')
            ->get()
            ->keyBy('stat_date');
    }

    /**
     * 今日支付方式占比原始行。
     *
     * @param string $start 开始时间
     * @param string $end 结束时间
     * @return \Illuminate\Support\Collection
     */
    public function payTypeShareRows(string $start, string $end)
    {
        return $this->payOrder->newQuery()
            ->from('ma_pay_order as p')
            ->leftJoin('ma_payment_type as t', 'p.pay_type_id', '=', 't.id')
            ->where('p.status', TradeConstant::ORDER_STATUS_SUCCESS)
            ->where('p.paid_at', '>=', $start)
            ->where('p.paid_at', '<', $end)
            ->selectRaw("COALESCE(t.name, '未知方式') AS name")
            ->selectRaw('COUNT(*) AS count_value')
            ->selectRaw('COALESCE(SUM(p.pay_amount), 0) AS amount_value')
            ->groupBy('name')
            ->orderByDesc('amount_value')
            ->limit(8)
            ->get();
    }

    /**
     * 最近异常通道原始行。
     *
     * @param string $statDate 统计日期
     * @return \Illuminate\Support\Collection
     */
    public function abnormalChannelRows(string $statDate)
    {
        return $this->abnormalChannelQuery($statDate)
            ->select([
                's.channel_id',
                's.pay_success_count',
                's.pay_fail_count',
                's.pay_amount',
                's.success_rate_bp',
                's.health_score',
                's.avg_latency_ms',
            ])
            ->selectRaw("COALESCE(c.name, '') AS channel_name")
            ->selectRaw("COALESCE(c.plugin_code, '') AS plugin_code")
            ->orderBy('s.health_score')
            ->orderByDesc('s.pay_fail_count')
            ->limit(6)
            ->get();
    }

    /**
     * 最近支付订单原始行。
     *
     * @return \Illuminate\Support\Collection
     */
    public function recentOrderRows()
    {
        return $this->payOrder->newQuery()
            ->from('ma_pay_order as p')
            ->leftJoin('ma_merchant as m', 'p.merchant_id', '=', 'm.id')
            ->leftJoin('ma_payment_channel as c', 'p.channel_id', '=', 'c.id')
            ->leftJoin('ma_payment_type as t', 'p.pay_type_id', '=', 't.id')
            ->select([
                'p.pay_no',
                'p.biz_no',
                'p.pay_amount',
                'p.status',
                'p.channel_error_msg',
                'p.created_at',
            ])
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(c.name, '') AS channel_name")
            ->selectRaw("COALESCE(t.name, '') AS pay_type_name")
            ->orderByDesc('p.id')
            ->limit(8)
            ->get();
    }

    /**
     * 构建异常通道基础查询。
     *
     * @param string $statDate 统计日期
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function abnormalChannelQuery(string $statDate)
    {
        return $this->channelDailyStat->newQuery()
            ->from('ma_channel_daily_stat as s')
            ->leftJoin('ma_payment_channel as c', 's.channel_id', '=', 'c.id')
            ->where('s.stat_date', $statDate)
            ->where(function ($builder) {
                $builder->where('s.health_score', '<', 60)
                    ->orWhere('s.pay_fail_count', '>', 0)
                    ->orWhere('s.avg_latency_ms', '>=', 3000)
                    ->orWhereRaw('(c.daily_limit_amount > 0 AND s.pay_amount * 100 >= c.daily_limit_amount * 80)')
                    ->orWhereRaw('(c.daily_limit_count > 0 AND (s.pay_success_count + s.pay_fail_count) * 100 >= c.daily_limit_count * 80)');
            });
    }

    /**
     * 构建路由配置风险基础查询。
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function routeMissingGroupBaseQuery()
    {
        return $this->merchantGroup->newQuery()
            ->from('ma_merchant_group as g')
            ->join('ma_merchant as m', 'm.group_id', '=', 'g.id')
            ->leftJoin('ma_payment_poll_group_bind as b', function ($join) {
                $join->on('b.merchant_group_id', '=', 'g.id')
                    ->where('b.status', CommonConstant::STATUS_ENABLED);
            })
            ->where('g.status', CommonConstant::STATUS_ENABLED)
            ->where('m.status', CommonConstant::STATUS_ENABLED)
            ->select([
                'g.id',
                'g.group_name',
            ])
            ->selectRaw('COUNT(DISTINCT m.id) AS merchant_count')
            ->selectRaw('COUNT(DISTINCT b.id) AS enabled_bind_count')
            ->groupBy('g.id', 'g.group_name')
            ->havingRaw('COUNT(DISTINCT b.id) = 0');
    }
}
