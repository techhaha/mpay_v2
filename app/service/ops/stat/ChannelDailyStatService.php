<?php

namespace app\service\ops\stat;

use app\common\base\BaseService;
use app\model\admin\ChannelDailyStat;
use app\repository\ops\stat\ChannelDailyStatRepository;

/**
 * 通道日统计查询服务。
 */
class ChannelDailyStatService extends BaseService
{
    /**
     * 构造函数，注入通道日统计仓库。
     */
    public function __construct(
        protected ChannelDailyStatRepository $channelDailyStatRepository
    ) {
    }

    /**
     * 分页查询通道日统计。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery();

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
     * 按 ID 查询详情。
     */
    public function findById(int $id): ?ChannelDailyStat
    {
        $row = $this->baseQuery()
            ->where('s.id', $id)
            ->first();

        return $row ?: null;
    }

    /**
     * 格式化单条统计记录。
     */
    private function decorateRow(object $row): object
    {
        $row->pay_amount_text = $this->formatAmount((int) $row->pay_amount);
        $row->refund_amount_text = $this->formatAmount((int) $row->refund_amount);
        $row->success_rate_text = $this->formatRate((int) $row->success_rate_bp);
        $row->avg_latency_ms_text = $this->formatLatency((int) $row->avg_latency_ms);
        $row->stat_date_text = $this->formatDate($row->stat_date ?? null);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);

        return $row;
    }

    /**
     * 构建基础查询。
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
            ->selectRaw("COALESCE(c.plugin_code, '') AS channel_plugin_code");
    }

}
