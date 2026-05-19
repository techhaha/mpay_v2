<?php

namespace app\service\account\freeze;

use app\common\base\BaseService;
use app\common\constant\FundFreezeConstant;
use app\model\merchant\MerchantFundFreeze;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\account\freeze\MerchantFundFreezeRepository;

/**
 * 商户资金冻结明细查询服务。
 *
 * 只负责后台查询和展示字段装配，不承载冻结或释放资金的写操作。
 *
 * @property MerchantFundFreezeRepository $fundFreezeRepository 资金冻结仓库
 * @property MerchantAccountRepository $accountRepository 商户账户仓库
 */
class MerchantFundFreezeService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantFundFreezeRepository $fundFreezeRepository 资金冻结仓库
     * @return void
     */
    public function __construct(
        protected MerchantFundFreezeRepository $fundFreezeRepository,
        protected MerchantAccountRepository $accountRepository
    ) {
    }

    /**
     * 分页查询资金冻结明细。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('f.freeze_no', 'like', '%' . $keyword . '%')
                    ->orWhere('f.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('f.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('f.trace_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%');
            });
        }

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('f.merchant_id', (int) $merchantId);
        }

        $freezeType = (string) ($filters['freeze_type'] ?? '');
        if ($freezeType !== '') {
            $query->where('f.freeze_type', (int) $freezeType);
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('f.status', (int) $status);
        }

        $paginator = $query
            ->orderByDesc('f.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRow($row);
        });

        return $paginator;
    }

    /**
     * 查询冻结明细详情。
     *
     * @param int $id 冻结明细ID
     * @return MerchantFundFreeze|null 冻结明细
     */
    public function findById(int $id): ?MerchantFundFreeze
    {
        $row = $this->baseQuery()
            ->where('f.id', $id)
            ->first();

        return $row ? $this->decorateRow($row) : null;
    }

    /**
     * 导出资金冻结明细 CSV。
     *
     * @param array $filters 筛选条件
     * @return \support\Response CSV 响应
     */
    public function exportCsv(array $filters = [])
    {
        $rows = $this->paginate($filters, 1, 5000)->items();
        $csvRows = [[
            '冻结单号',
            '商户号',
            '商户名称',
            '业务单号',
            '支付单号',
            '追踪号',
            '冻结类型',
            '冻结金额',
            '剩余金额',
            '已释放金额',
            '状态',
            '冻结原因',
            '释放原因',
            '冻结时间',
            '释放时间',
        ]];

        foreach ($rows as $row) {
            $csvRows[] = [
                (string) ($row->freeze_no ?? ''),
                (string) ($row->merchant_no ?? ''),
                (string) ($row->merchant_name ?? ''),
                (string) ($row->biz_no ?? ''),
                (string) ($row->pay_no ?? ''),
                (string) ($row->trace_no ?? ''),
                (string) ($row->freeze_type_text ?? ''),
                (string) ($row->freeze_amount_text ?? ''),
                (string) ($row->remaining_amount_text ?? ''),
                (string) ($row->released_amount_text ?? ''),
                (string) ($row->status_text ?? ''),
                (string) ($row->reason ?? ''),
                (string) ($row->release_reason ?? ''),
                (string) ($row->frozen_at_text ?? ''),
                (string) ($row->released_at_text ?? ''),
            ];
        }

        return $this->csvResponse($csvRows, 'fund-freezes-' . date('YmdHis') . '.csv');
    }

    /**
     * 获取冻结余额对账摘要。
     *
     * @return array<string, mixed> 对账摘要
     */
    public function reconciliationSummary(): array
    {
        $accountStats = $this->accountRepository->query()
            ->selectRaw('COUNT(*) AS account_count')
            ->selectRaw('COALESCE(SUM(frozen_balance), 0) AS account_frozen_amount')
            ->first();

        $freezeStats = $this->fundFreezeRepository->query()
            ->selectRaw('COUNT(*) AS active_freeze_count')
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) AS active_freeze_amount')
            ->where('status', FundFreezeConstant::STATUS_ACTIVE)
            ->where('remaining_amount', '>', 0)
            ->first();

        $mismatchRows = $this->accountRepository->query()
            ->from('ma_merchant_account as a')
            ->leftJoin('ma_merchant as m', 'a.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->leftJoinSub(
                $this->fundFreezeRepository->query()
                    ->from('ma_merchant_fund_freeze')
                    ->selectRaw('merchant_id, COALESCE(SUM(remaining_amount), 0) AS active_freeze_amount')
                    ->where('status', FundFreezeConstant::STATUS_ACTIVE)
                    ->where('remaining_amount', '>', 0)
                    ->groupBy('merchant_id'),
                'ff',
                'a.merchant_id',
                '=',
                'ff.merchant_id'
            )
            ->select([
                'a.merchant_id',
                'a.frozen_balance',
            ])
            ->selectRaw('COALESCE(ff.active_freeze_amount, 0) AS active_freeze_amount')
            ->selectRaw('(a.frozen_balance - COALESCE(ff.active_freeze_amount, 0)) AS diff_amount')
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name")
            ->whereRaw('a.frozen_balance <> COALESCE(ff.active_freeze_amount, 0)')
            ->orderByRaw('ABS(a.frozen_balance - COALESCE(ff.active_freeze_amount, 0)) DESC')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $row->frozen_balance_text = $this->formatAmount((int) $row->frozen_balance);
                $row->active_freeze_amount_text = $this->formatAmount((int) $row->active_freeze_amount);
                $row->diff_amount_text = $this->formatSignedAmount((int) $row->diff_amount);

                return $row;
            })
            ->values()
            ->all();

        $accountFrozenAmount = (int) ($accountStats->account_frozen_amount ?? 0);
        $activeFreezeAmount = (int) ($freezeStats->active_freeze_amount ?? 0);
        $diffAmount = $accountFrozenAmount - $activeFreezeAmount;

        return [
            'account_count' => (int) ($accountStats->account_count ?? 0),
            'active_freeze_count' => (int) ($freezeStats->active_freeze_count ?? 0),
            'account_frozen_amount' => $accountFrozenAmount,
            'account_frozen_amount_text' => $this->formatAmount($accountFrozenAmount),
            'active_freeze_amount' => $activeFreezeAmount,
            'active_freeze_amount_text' => $this->formatAmount($activeFreezeAmount),
            'diff_amount' => $diffAmount,
            'diff_amount_text' => $this->formatSignedAmount($diffAmount),
            'is_balanced' => $diffAmount === 0 && count($mismatchRows) === 0,
            'mismatch_count' => count($mismatchRows),
            'mismatch_rows' => $mismatchRows,
        ];
    }

    /**
     * 格式化展示字段。
     *
     * @param object $row 原始查询行
     * @return object 格式化后的记录
     */
    private function decorateRow(object $row): object
    {
        $row->freeze_type_text = (string) (FundFreezeConstant::typeMap()[(int) $row->freeze_type] ?? '未知');
        $row->status_text = (string) (FundFreezeConstant::statusMap()[(int) $row->status] ?? '未知');
        $row->freeze_amount_text = $this->formatAmount((int) $row->freeze_amount);
        $row->remaining_amount_text = $this->formatAmount((int) $row->remaining_amount);
        $row->released_amount = max(0, (int) $row->freeze_amount - (int) $row->remaining_amount);
        $row->released_amount_text = $this->formatAmount((int) $row->released_amount);
        $row->available_at_text = $this->formatDateTime($row->available_at ?? null, '—');
        $row->frozen_at_text = $this->formatDateTime($row->frozen_at ?? null, '—');
        $row->released_at_text = $this->formatDateTime($row->released_at ?? null, '—');
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null, '—');
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null, '—');

        return $row;
    }

    /**
     * 格式化带符号金额。
     *
     * @param int $amount 金额，单位分
     * @return string 带符号金额
     */
    private function formatSignedAmount(int $amount): string
    {
        if ($amount === 0) {
            return '0.00';
        }

        return ($amount > 0 ? '+' : '-') . $this->formatAmount(abs($amount));
    }

    /**
     * 构建查询。
     *
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function baseQuery()
    {
        return $this->fundFreezeRepository->query()
            ->from('ma_merchant_fund_freeze as f')
            ->leftJoin('ma_merchant as m', 'f.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'f.id',
                'f.freeze_no',
                'f.merchant_id',
                'f.biz_no',
                'f.pay_no',
                'f.trace_no',
                'f.freeze_type',
                'f.freeze_amount',
                'f.remaining_amount',
                'f.status',
                'f.reason',
                'f.admin_id',
                'f.available_at',
                'f.frozen_at',
                'f.release_reason',
                'f.released_by',
                'f.released_at',
                'f.created_at',
                'f.updated_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name");
    }

    /**
     * 构建 CSV 下载响应。
     *
     * @param array<int, array<int, string>> $rows CSV 行
     * @param string $filename 文件名
     * @return \support\Response 响应
     */
    private function csvResponse(array $rows, string $filename)
    {
        $fp = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        $body = "\xEF\xBB\xBF" . stream_get_contents($fp);
        fclose($fp);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n", "\0"], '', $filename) . '"',
        ]);
    }
}
