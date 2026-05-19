<?php

namespace app\service\ops\stat;

use app\common\base\BaseService;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\model\admin\ChannelDailyStat;
use app\repository\ops\stat\ChannelDailyStatRepository;

/**
 * 通道日统计查询服务。
 *
 * 负责渠道日统计列表、详情和展示字段补充。
 *
 * @property ChannelDailyStatRepository $channelDailyStatRepository 渠道日统计仓库
 */
class ChannelDailyStatService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param ChannelDailyStatRepository $channelDailyStatRepository 渠道日统计仓库
     * @return void
     */
    public function __construct(
        protected ChannelDailyStatRepository $channelDailyStatRepository
    ) {
    }

    /**
     * 分页查询通道日统计。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->applyFilters($this->baseQuery(), $filters);

        $paginator = $query
            ->orderByDesc('s.stat_date')
            ->orderByDesc('s.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRow($row);
        });

        return $paginator;
    }

    /**
     * 汇总通道健康面板。
     *
     * @param array $filters 筛选条件
     * @return array<string, mixed> 通道健康摘要
     */
    public function summary(array $filters = []): array
    {
        $query = $this->applyFilters($this->baseFilterQuery(), $filters);

        $totalRow = (clone $query)
            ->selectRaw('COUNT(*) AS stat_count')
            ->selectRaw('COALESCE(SUM(s.pay_success_count), 0) AS total_success_count')
            ->selectRaw('COALESCE(SUM(s.pay_fail_count), 0) AS total_fail_count')
            ->selectRaw('COALESCE(SUM(s.pay_amount), 0) AS total_pay_amount')
            ->selectRaw('COALESCE(SUM(s.pay_success_count + s.pay_fail_count), 0) AS total_pay_count')
            ->selectRaw('COALESCE(SUM(s.avg_latency_ms * s.pay_success_count), 0) AS weighted_latency_sum')
            ->selectRaw('COALESCE(SUM(s.pay_success_count), 0) AS latency_weight_count')
            ->first();

        $totalSuccessCount = (int) ($totalRow->total_success_count ?? 0);
        $totalFailCount = (int) ($totalRow->total_fail_count ?? 0);
        $totalPayCount = (int) ($totalRow->total_pay_count ?? 0);
        $latencyWeightCount = (int) ($totalRow->latency_weight_count ?? 0);
        $avgLatencyMs = $latencyWeightCount > 0
            ? (int) floor((int) ($totalRow->weighted_latency_sum ?? 0) / $latencyWeightCount)
            : 0;
        $successRateBp = $totalPayCount > 0 ? (int) floor($totalSuccessCount * 10000 / $totalPayCount) : 0;

        $poorCount = (clone $query)
            ->whereRaw('(s.pay_success_count + s.pay_fail_count) > 0')
            ->where('s.health_score', '<', 60)
            ->count();
        $warningCount = (clone $query)
            ->whereRaw('(s.pay_success_count + s.pay_fail_count) > 0')
            ->whereBetween('s.health_score', [60, 79])
            ->count();
        $limitWarningCount = (clone $query)
            ->where(function ($builder) {
                $builder->whereRaw('(c.daily_limit_amount > 0 AND s.pay_amount * 100 >= c.daily_limit_amount * 80)')
                    ->orWhereRaw('(c.daily_limit_count > 0 AND (s.pay_success_count + s.pay_fail_count) * 100 >= c.daily_limit_count * 80)');
            })
            ->count();

        $recentAbnormal = $this->applyFilters($this->baseQuery(), $filters)
            ->where(function ($builder) {
                $builder->where('s.health_score', '<', 60)
                    ->orWhere('s.pay_fail_count', '>', 0)
                    ->orWhere('s.avg_latency_ms', '>=', 3000)
                    ->orWhereRaw('(c.daily_limit_amount > 0 AND s.pay_amount * 100 >= c.daily_limit_amount * 80)')
                    ->orWhereRaw('(c.daily_limit_count > 0 AND (s.pay_success_count + s.pay_fail_count) * 100 >= c.daily_limit_count * 80)');
            })
            ->orderByDesc('s.stat_date')
            ->orderByDesc('s.id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => $this->decorateRow($row))
            ->values()
            ->all();

        return [
            'stat_count' => (int) ($totalRow->stat_count ?? 0),
            'healthy_count' => max(0, (int) ($totalRow->stat_count ?? 0) - (int) $poorCount - (int) $warningCount),
            'warning_count' => (int) $warningCount,
            'poor_count' => (int) $poorCount,
            'limit_warning_count' => (int) $limitWarningCount,
            'total_success_count' => $totalSuccessCount,
            'total_fail_count' => $totalFailCount,
            'total_pay_count' => $totalPayCount,
            'total_pay_amount' => (int) ($totalRow->total_pay_amount ?? 0),
            'total_pay_amount_text' => $this->formatAmount((int) ($totalRow->total_pay_amount ?? 0)),
            'success_rate_bp' => $successRateBp,
            'success_rate_text' => $this->formatRate($successRateBp),
            'avg_latency_ms' => $avgLatencyMs,
            'avg_latency_ms_text' => $this->formatLatency($avgLatencyMs),
            'recent_abnormal' => $recentAbnormal,
        ];
    }

    /**
     * 按 ID 查询渠道日统计详情。
     *
     * @param int $id 渠道日统计ID
     * @return ChannelDailyStat|null 统计模型
     */
    public function findById(int $id): ?ChannelDailyStat
    {
        $row = $this->baseQuery()
            ->where('s.id', $id)
            ->first();

        return $row ? $this->decorateRow($row) : null;
    }

    /**
     * 记录支付成功统计。
     *
     * @param PayOrder $payOrder 支付单
     * @return void
     */
    public function recordPaySuccess(PayOrder $payOrder): void
    {
        $this->applyDelta($this->buildBaseDelta($payOrder) + [
            'pay_success_count' => 1,
            'pay_fail_count' => 0,
            'pay_amount' => (int) $payOrder->pay_amount,
            'refund_count' => 0,
            'refund_amount' => 0,
            'latency_ms' => $this->resolvePayLatencyMs($payOrder),
        ]);
    }

    /**
     * 记录支付失败类统计。
     *
     * @param PayOrder $payOrder 支付单
     * @return void
     */
    public function recordPayFailure(PayOrder $payOrder): void
    {
        $this->applyDelta($this->buildBaseDelta($payOrder) + [
            'pay_success_count' => 0,
            'pay_fail_count' => 1,
            'pay_amount' => 0,
            'refund_count' => 0,
            'refund_amount' => 0,
            'latency_ms' => 0,
        ]);
    }

    /**
     * 记录退款成功统计。
     *
     * @param RefundOrder $refundOrder 退款单
     * @return void
     */
    public function recordRefundSuccess(RefundOrder $refundOrder): void
    {
        if ((int) $refundOrder->channel_id <= 0) {
            return;
        }

        $this->applyDelta([
            'merchant_id' => (int) $refundOrder->merchant_id,
            'merchant_group_id' => (int) $refundOrder->merchant_group_id,
            'channel_id' => (int) $refundOrder->channel_id,
            'stat_date' => $this->resolveDate($refundOrder->succeeded_at ?: $refundOrder->updated_at ?: $refundOrder->created_at),
            'pay_success_count' => 0,
            'pay_fail_count' => 0,
            'pay_amount' => 0,
            'refund_count' => 1,
            'refund_amount' => (int) $refundOrder->refund_amount,
            'latency_ms' => 0,
        ]);
    }

    /**
     * 格式化单条统计记录。
     *
     * @param object $row 查询结果对象
     * @return object 格式化后的对象
     */
    private function decorateRow(object $row): object
    {
        $row->pay_amount_text = $this->formatAmount((int) $row->pay_amount);
        $row->refund_amount_text = $this->formatAmount((int) $row->refund_amount);
        $row->success_rate_text = $this->formatRate((int) $row->success_rate_bp);
        $row->avg_latency_ms_text = $this->formatLatency((int) $row->avg_latency_ms);
        $row->limit_amount_usage_text = $this->formatLimitAmountUsage((int) $row->pay_amount, (int) ($row->daily_limit_amount ?? 0));
        $row->limit_count_usage_text = $this->formatLimitCountUsage(
            (int) $row->pay_success_count + (int) $row->pay_fail_count,
            (int) ($row->daily_limit_count ?? 0)
        );
        $row->failure_reason_text = $this->resolveFailureReason($row);
        $row->health_status_text = $this->resolveHealthStatus((int) $row->health_score);
        $row->stat_date_text = $this->formatDate($row->stat_date ?? null);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);

        return $row;
    }

    /**
     * 构建支付单统计基础维度。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed> 统计维度
     */
    private function buildBaseDelta(PayOrder $payOrder): array
    {
        return [
            'merchant_id' => (int) $payOrder->merchant_id,
            'merchant_group_id' => (int) $payOrder->merchant_group_id,
            'channel_id' => (int) $payOrder->channel_id,
            'stat_date' => $this->resolveDate($payOrder->paid_at ?: $payOrder->failed_at ?: $payOrder->closed_at ?: $payOrder->timeout_at ?: $payOrder->updated_at ?: $payOrder->created_at),
        ];
    }

    /**
     * 应用统计增量。
     *
     * @param array<string, mixed> $delta 统计增量
     * @return void
     */
    private function applyDelta(array $delta): void
    {
        $channelId = (int) ($delta['channel_id'] ?? 0);
        if ($channelId <= 0) {
            return;
        }

        $this->transactionRetry(function () use ($delta, $channelId): void {
            $statDate = (string) ($delta['stat_date'] ?? date('Y-m-d'));
            $row = $this->channelDailyStatRepository->findForUpdateByChannelAndDate($channelId, $statDate);
            if (!$row) {
                $row = $this->channelDailyStatRepository->create([
                    'merchant_id' => (int) ($delta['merchant_id'] ?? 0),
                    'merchant_group_id' => (int) ($delta['merchant_group_id'] ?? 0),
                    'channel_id' => $channelId,
                    'stat_date' => $statDate,
                    'pay_success_count' => 0,
                    'pay_fail_count' => 0,
                    'pay_amount' => 0,
                    'refund_count' => 0,
                    'refund_amount' => 0,
                    'avg_latency_ms' => 0,
                    'success_rate_bp' => 0,
                    'health_score' => 0,
                ]);
                $row = $this->channelDailyStatRepository->findForUpdateByChannelAndDate($channelId, $statDate) ?: $row;
            }

            $previousSuccess = (int) $row->pay_success_count;
            $successDelta = (int) ($delta['pay_success_count'] ?? 0);
            $latencyMs = (int) ($delta['latency_ms'] ?? 0);

            $row->merchant_id = (int) ($row->merchant_id ?: ($delta['merchant_id'] ?? 0));
            $row->merchant_group_id = (int) ($row->merchant_group_id ?: ($delta['merchant_group_id'] ?? 0));
            $row->pay_success_count = $previousSuccess + $successDelta;
            $row->pay_fail_count = (int) $row->pay_fail_count + (int) ($delta['pay_fail_count'] ?? 0);
            $row->pay_amount = (int) $row->pay_amount + (int) ($delta['pay_amount'] ?? 0);
            $row->refund_count = (int) $row->refund_count + (int) ($delta['refund_count'] ?? 0);
            $row->refund_amount = (int) $row->refund_amount + (int) ($delta['refund_amount'] ?? 0);

            if ($successDelta > 0 && $latencyMs > 0) {
                $row->avg_latency_ms = $previousSuccess > 0
                    ? (int) floor(((int) $row->avg_latency_ms * $previousSuccess + $latencyMs) / max(1, $previousSuccess + $successDelta))
                    : $latencyMs;
            }

            $this->refreshQualityFields($row);
            $row->save();
        });
    }

    /**
     * 刷新成功率和健康分。
     *
     * 成功率以万分比保存，健康分以成功率百分制为基础，并按平均耗时做轻量扣分。
     *
     * @param ChannelDailyStat $row 统计记录
     * @return void
     */
    private function refreshQualityFields(ChannelDailyStat $row): void
    {
        $successCount = (int) $row->pay_success_count;
        $failCount = (int) $row->pay_fail_count;
        $total = $successCount + $failCount;

        $row->success_rate_bp = $total > 0 ? (int) floor($successCount * 10000 / $total) : 0;
        $latencyPenalty = min(30, (int) floor((int) $row->avg_latency_ms / 1000));
        $row->health_score = $total > 0 ? max(0, min(100, (int) floor($row->success_rate_bp / 100) - $latencyPenalty)) : 0;
    }

    /**
     * 计算支付成功耗时。
     *
     * @param PayOrder $payOrder 支付单
     * @return int 耗时毫秒数
     */
    private function resolvePayLatencyMs(PayOrder $payOrder): int
    {
        $start = strtotime((string) ($payOrder->request_at ?: $payOrder->created_at));
        $end = strtotime((string) ($payOrder->paid_at ?: $payOrder->updated_at));
        if (!$start || !$end || $end < $start) {
            return 0;
        }

        return (int) (($end - $start) * 1000);
    }

    /**
     * 解析统计日期。
     *
     * @param mixed $value 时间值
     * @return string 日期，格式 Y-m-d
     */
    private function resolveDate(mixed $value): string
    {
        $timestamp = strtotime((string) $value);

        return $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }

    /**
     * 构建基础查询。
     *
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function baseQuery()
    {
        return $this->channelDailyStatRepository->query()
            ->from('ma_channel_daily_stat as s')
            ->leftJoin('ma_merchant as m', 's.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 's.merchant_group_id', '=', 'g.id')
            ->leftJoin('ma_payment_channel as c', 's.channel_id', '=', 'c.id')
            ->select([
                's.id',
                's.merchant_id',
                's.merchant_group_id',
                's.channel_id',
                's.stat_date',
                's.pay_success_count',
                's.pay_fail_count',
                's.pay_amount',
                's.refund_count',
                's.refund_amount',
                's.avg_latency_ms',
                's.success_rate_bp',
                's.health_score',
                's.created_at',
                's.updated_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name")
            ->selectRaw("COALESCE(c.name, '') AS channel_name")
            ->selectRaw("COALESCE(c.plugin_code, '') AS channel_plugin_code")
            ->selectRaw('COALESCE(c.daily_limit_amount, 0) AS daily_limit_amount')
            ->selectRaw('COALESCE(c.daily_limit_count, 0) AS daily_limit_count');
    }

    /**
     * 构建只用于筛选和统计的基础查询。
     *
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function baseFilterQuery()
    {
        return $this->channelDailyStatRepository->query()
            ->from('ma_channel_daily_stat as s')
            ->leftJoin('ma_merchant as m', 's.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 's.merchant_group_id', '=', 'g.id')
            ->leftJoin('ma_payment_channel as c', 's.channel_id', '=', 'c.id');
    }

    /**
     * 应用通道日统计筛选条件。
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 查询构造器
     * @param array $filters 筛选条件
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function applyFilters($query, array $filters)
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('s.stat_date', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.plugin_code', 'like', '%' . $keyword . '%');
            });
        }

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('s.merchant_id', (int) $merchantId);
        }

        $channelId = (string) ($filters['channel_id'] ?? '');
        if ($channelId !== '') {
            $query->where('s.channel_id', (int) $channelId);
        }

        $statDate = trim((string) ($filters['stat_date'] ?? ''));
        if ($statDate !== '') {
            $query->where('s.stat_date', $statDate);
        }

        return $query;
    }

    /**
     * 格式化金额限额使用。
     *
     * @param int $usedAmount 已用金额，单位分
     * @param int $limitAmount 限额，单位分
     * @return string 限额使用文案
     */
    private function formatLimitAmountUsage(int $usedAmount, int $limitAmount): string
    {
        if ($limitAmount <= 0) {
            return '未配置';
        }

        return $this->formatAmount($usedAmount) . ' / ' . $this->formatAmount($limitAmount) . '（' . $this->formatUsageRate($usedAmount, $limitAmount) . '）';
    }

    /**
     * 格式化笔数限额使用。
     *
     * @param int $usedCount 已用笔数
     * @param int $limitCount 限笔
     * @return string 限笔使用文案
     */
    private function formatLimitCountUsage(int $usedCount, int $limitCount): string
    {
        if ($limitCount <= 0) {
            return '未配置';
        }

        return $usedCount . ' / ' . $limitCount . '（' . $this->formatUsageRate($usedCount, $limitCount) . '）';
    }

    /**
     * 格式化使用率。
     *
     * @param int $used 已用值
     * @param int $limit 限制值
     * @return string 使用率文案
     */
    private function formatUsageRate(int $used, int $limit): string
    {
        if ($limit <= 0) {
            return '0%';
        }

        return number_format(min(999.99, $used * 100 / $limit), 2) . '%';
    }

    /**
     * 推断异常摘要。
     *
     * @param object $row 统计行
     * @return string 异常摘要
     */
    private function resolveFailureReason(object $row): string
    {
        $payCount = (int) $row->pay_success_count + (int) $row->pay_fail_count;
        if ((int) $row->pay_fail_count > 0 && (int) $row->pay_success_count <= 0) {
            return '当日支付全部失败，优先检查上游状态和通道配置';
        }
        if ((int) $row->pay_fail_count > 0) {
            return '存在失败订单，建议结合订单详情查看上游返回';
        }
        if ((int) $row->avg_latency_ms >= 3000) {
            return '平均耗时偏高，可能存在上游响应慢';
        }
        if ($payCount > 0 && (int) $row->health_score < 60) {
            return '健康分偏低，建议检查成功率和耗时';
        }
        if ((int) ($row->daily_limit_amount ?? 0) > 0 && (int) $row->pay_amount * 100 >= (int) $row->daily_limit_amount * 80) {
            return '金额限额使用接近上限';
        }
        if ((int) ($row->daily_limit_count ?? 0) > 0 && $payCount * 100 >= (int) $row->daily_limit_count * 80) {
            return '笔数限额使用接近上限';
        }

        return '暂无明显异常';
    }

    /**
     * 根据健康分生成状态文案。
     *
     * @param int $score 健康分
     * @return string 状态文案
     */
    private function resolveHealthStatus(int $score): string
    {
        if ($score >= 80) {
            return '健康';
        }
        if ($score >= 60) {
            return '关注';
        }

        return '异常';
    }
}
