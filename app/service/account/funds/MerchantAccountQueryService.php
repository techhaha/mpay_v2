<?php

namespace app\service\account\funds;

use app\common\base\BaseService;
use app\common\constant\FundFreezeConstant;
use app\model\merchant\MerchantAccount;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\account\freeze\MerchantFundFreezeRepository;
use app\repository\account\ledger\MerchantAccountLedgerRepository;

/**
 * 商户账户查询服务。
 *
 * 只负责账户列表、概览和快照查询，不承载资金变更逻辑。
 *
 * @property MerchantAccountRepository $accountRepository 账户仓库
 * @property MerchantAccountLedgerRepository $ledgerRepository 流水仓库
 */
class MerchantAccountQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountRepository $accountRepository 账户仓库
     * @param MerchantAccountLedgerRepository $ledgerRepository 流水仓库
     * @return void
     */
    public function __construct(
        protected MerchantAccountRepository $accountRepository,
        protected MerchantAccountLedgerRepository $ledgerRepository,
        protected MerchantFundFreezeRepository $fundFreezeRepository
    ) {
    }

    /**
     * 分页查询商户账户。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->accountRepository->query()
            ->from('ma_merchant_account as a')
            ->leftJoin('ma_merchant as m', 'a.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'a.id',
                'a.merchant_id',
                'a.available_balance',
                'a.frozen_balance',
                'a.created_at',
                'a.updated_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name");

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%');
            });
        }

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('a.merchant_id', (int) $merchantId);
        }

        $paginator = $query
            ->orderByDesc('a.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            $row->available_balance_text = $this->formatAmount((int) $row->available_balance);
            $row->frozen_balance_text = $this->formatAmount((int) $row->frozen_balance);

            return $row;
        });

        return $paginator;
    }

    /**
     * 资金中心概览。
     *
     * @return array 概览数据
     */
    public function summary(): array
    {
        $accountStats = $this->accountRepository->query()
            ->selectRaw('COUNT(*) AS account_count')
            ->selectRaw('SUM(available_balance) AS total_available_balance')
            ->selectRaw('SUM(frozen_balance) AS total_frozen_balance')
            ->first();

        $ledgerStats = $this->ledgerRepository->query()
            ->selectRaw('COUNT(*) AS ledger_count')
            ->first();

        $totalAvailableBalance = (int) ($accountStats->total_available_balance ?? 0);
        $totalFrozenBalance = (int) ($accountStats->total_frozen_balance ?? 0);

        return [
            'account_count' => (int) ($accountStats->account_count ?? 0),
            'ledger_count' => (int) ($ledgerStats->ledger_count ?? 0),
            'total_available_balance' => $totalAvailableBalance,
            'total_available_balance_text' => $this->formatAmount($totalAvailableBalance),
            'total_frozen_balance' => $totalFrozenBalance,
            'total_frozen_balance_text' => $this->formatAmount($totalFrozenBalance),
        ];
    }

    /**
     * 获取账户、流水和冻结明细完整对账视图。
     *
     * @param array $filters 筛选条件
     * @return array<string, mixed> 对账结果
     */
    public function reconciliation(array $filters = []): array
    {
        $query = $this->accountRepository->query()
            ->from('ma_merchant_account as a')
            ->leftJoin('ma_merchant as m', 'a.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->leftJoinSub(
                $this->ledgerRepository->query()
                    ->from('ma_merchant_account_ledger as latest')
                    ->joinSub(
                        $this->ledgerRepository->query()
                            ->from('ma_merchant_account_ledger')
                            ->selectRaw('merchant_id, MAX(id) AS latest_id')
                            ->groupBy('merchant_id'),
                        'lm',
                        'latest.id',
                        '=',
                        'lm.latest_id'
                    )
                    ->select([
                        'latest.merchant_id',
                        'latest.available_after',
                        'latest.frozen_after',
                        'latest.ledger_no',
                        'latest.created_at',
                    ]),
                'll',
                'a.merchant_id',
                '=',
                'll.merchant_id'
            )
            ->leftJoinSub(
                $this->fundFreezeRepository->query()
                    ->from('ma_merchant_fund_freeze')
                    ->selectRaw('merchant_id, COUNT(*) AS active_freeze_count, COALESCE(SUM(remaining_amount), 0) AS active_freeze_amount')
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
                'a.available_balance',
                'a.frozen_balance',
            ])
            ->selectRaw('COALESCE(m.merchant_no, \'\') AS merchant_no')
            ->selectRaw('COALESCE(m.merchant_name, \'\') AS merchant_name')
            ->selectRaw('COALESCE(g.group_name, \'\') AS merchant_group_name')
            ->selectRaw('COALESCE(ll.available_after, 0) AS ledger_available_balance')
            ->selectRaw('COALESCE(ll.frozen_after, 0) AS ledger_frozen_balance')
            ->selectRaw('COALESCE(ll.ledger_no, \'\') AS latest_ledger_no')
            ->selectRaw('ll.created_at AS latest_ledger_at')
            ->selectRaw('COALESCE(ff.active_freeze_count, 0) AS active_freeze_count')
            ->selectRaw('COALESCE(ff.active_freeze_amount, 0) AS active_freeze_amount');

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('a.merchant_id', (int) $merchantId);
        }

        $rows = $query
            ->orderByDesc('a.id')
            ->get()
            ->map(function ($row) {
                $row->available_ledger_diff = (int) $row->available_balance - (int) $row->ledger_available_balance;
                $row->frozen_ledger_diff = (int) $row->frozen_balance - (int) $row->ledger_frozen_balance;
                $row->frozen_freeze_diff = (int) $row->frozen_balance - (int) $row->active_freeze_amount;
                $row->is_balanced = $row->available_ledger_diff === 0
                    && $row->frozen_ledger_diff === 0
                    && $row->frozen_freeze_diff === 0;

                $row->available_balance_text = $this->formatAmount((int) $row->available_balance);
                $row->frozen_balance_text = $this->formatAmount((int) $row->frozen_balance);
                $row->ledger_available_balance_text = $this->formatAmount((int) $row->ledger_available_balance);
                $row->ledger_frozen_balance_text = $this->formatAmount((int) $row->ledger_frozen_balance);
                $row->active_freeze_amount_text = $this->formatAmount((int) $row->active_freeze_amount);
                $row->available_ledger_diff_text = $this->formatSignedAmount((int) $row->available_ledger_diff);
                $row->frozen_ledger_diff_text = $this->formatSignedAmount((int) $row->frozen_ledger_diff);
                $row->frozen_freeze_diff_text = $this->formatSignedAmount((int) $row->frozen_freeze_diff);
                $row->latest_ledger_at_text = $this->formatDateTime($row->latest_ledger_at ?? null, '—');

                return $row;
            })
            ->values()
            ->all();

        $mismatchRows = array_values(array_filter($rows, static fn ($row) => !$row->is_balanced));
        $accountAvailableAmount = array_sum(array_map(static fn ($row) => (int) $row->available_balance, $rows));
        $accountFrozenAmount = array_sum(array_map(static fn ($row) => (int) $row->frozen_balance, $rows));
        $ledgerAvailableAmount = array_sum(array_map(static fn ($row) => (int) $row->ledger_available_balance, $rows));
        $ledgerFrozenAmount = array_sum(array_map(static fn ($row) => (int) $row->ledger_frozen_balance, $rows));
        $activeFreezeAmount = array_sum(array_map(static fn ($row) => (int) $row->active_freeze_amount, $rows));

        return [
            'account_count' => count($rows),
            'mismatch_count' => count($mismatchRows),
            'is_balanced' => count($mismatchRows) === 0,
            'account_available_amount' => $accountAvailableAmount,
            'account_available_amount_text' => $this->formatAmount($accountAvailableAmount),
            'ledger_available_amount' => $ledgerAvailableAmount,
            'ledger_available_amount_text' => $this->formatAmount($ledgerAvailableAmount),
            'available_diff_amount' => $accountAvailableAmount - $ledgerAvailableAmount,
            'available_diff_amount_text' => $this->formatSignedAmount($accountAvailableAmount - $ledgerAvailableAmount),
            'account_frozen_amount' => $accountFrozenAmount,
            'account_frozen_amount_text' => $this->formatAmount($accountFrozenAmount),
            'ledger_frozen_amount' => $ledgerFrozenAmount,
            'ledger_frozen_amount_text' => $this->formatAmount($ledgerFrozenAmount),
            'active_freeze_amount' => $activeFreezeAmount,
            'active_freeze_amount_text' => $this->formatAmount($activeFreezeAmount),
            'frozen_ledger_diff_amount' => $accountFrozenAmount - $ledgerFrozenAmount,
            'frozen_ledger_diff_amount_text' => $this->formatSignedAmount($accountFrozenAmount - $ledgerFrozenAmount),
            'frozen_freeze_diff_amount' => $accountFrozenAmount - $activeFreezeAmount,
            'frozen_freeze_diff_amount_text' => $this->formatSignedAmount($accountFrozenAmount - $activeFreezeAmount),
            'mismatch_rows' => array_slice($mismatchRows, 0, 20),
        ];
    }

    /**
     * 获取商户余额快照。
     *
     * 用于后台展示和接口返回，不修改任何账户数据。
     *
     * @param int $merchantId 商户ID
     * @return array 快照数据
     */
    public function getBalanceSnapshot(int $merchantId): array
    {
        $account = $this->accountRepository->findByMerchantId($merchantId);

        if (!$account) {
            return [
                'merchant_id' => $merchantId,
                'available_balance' => 0,
                'frozen_balance' => 0,
            ];
        }

        return [
            'merchant_id' => (int) $account->merchant_id,
            'available_balance' => (int) $account->available_balance,
            'frozen_balance' => (int) $account->frozen_balance,
        ];
    }

    /**
     * 查询商户账户详情。
     *
     * @param int $id 商户账户查询ID
     * @return MerchantAccount|null 账户记录
     */
    public function findById(int $id): ?MerchantAccount
    {
        $row = $this->accountRepository->query()
            ->from('ma_merchant_account as a')
            ->leftJoin('ma_merchant as m', 'a.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'a.id',
                'a.merchant_id',
                'a.available_balance',
                'a.frozen_balance',
                'a.created_at',
                'a.updated_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name")
            ->where('a.id', $id)
            ->first();

        if (!$row) {
            return null;
        }

        $row->available_balance_text = $this->formatAmount((int) $row->available_balance);
        $row->frozen_balance_text = $this->formatAmount((int) $row->frozen_balance);

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

}


