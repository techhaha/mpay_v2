<?php

namespace app\service\payment\settlement;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\SettlementOrder;
use app\repository\account\ledger\MerchantAccountLedgerRepository;
use app\repository\payment\settlement\SettlementItemRepository;
use app\repository\payment\settlement\SettlementOrderRepository;

/**
 * 清算订单查询服务。
 *
 * 负责清算订单的列表、详情、时间线和关联流水装配。
 *
 * @property SettlementOrderRepository $settlementOrderRepository 结算订单仓库
 * @property SettlementItemRepository $settlementItemRepository 结算明细仓库
 * @property MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
 */
class SettlementOrderQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param SettlementOrderRepository $settlementOrderRepository 结算订单仓库
     * @param SettlementItemRepository $settlementItemRepository 结算明细仓库
     * @param MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
     * @return void
     */
    public function __construct(
        protected SettlementOrderRepository $settlementOrderRepository,
        protected SettlementItemRepository $settlementItemRepository,
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository
    ) {
    }

    /**
     * 分页查询清算订单。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户ID
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null)
    {
        $query = $this->applyFilters($this->baseQuery($merchantId), $filters);

        $paginator = $query
            ->orderByDesc('s.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        // 列表页需要直接展示文本字段，所以这里统一把每一行补成可渲染结构。
        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRow($row);
        });

        return $paginator;
    }

    /**
     * 统计清算异常处理摘要。
     *
     * 摘要只应用商户、通道和周期筛选，不应用状态筛选，方便页面切换状态时仍能看到全局待处理量。
     *
     * @param array $filters 筛选条件
     * @param int|null $merchantId 商户ID
     * @return array<string, mixed> 摘要结构
     */
    public function summary(array $filters = [], ?int $merchantId = null): array
    {
        $summaryFilters = $filters;
        unset($summaryFilters['status'], $summaryFilters['page'], $summaryFilters['page_size']);

        $base = $this->applyFilters($this->baseFilterQuery($merchantId), $summaryFilters);
        $rows = $base
            ->selectRaw('s.status AS group_status')
            ->selectRaw('COUNT(*) AS count_value')
            ->selectRaw('COALESCE(SUM(s.net_amount), 0) AS net_amount_value')
            ->selectRaw('COALESCE(SUM(s.accounted_amount), 0) AS accounted_amount_value')
            ->groupBy('s.status')
            ->get();

        $result = [
            'pending_count' => 0,
            'pending_net_amount' => 0,
            'pending_net_amount_text' => $this->formatAmount(0),
            'settled_count' => 0,
            'settled_accounted_amount' => 0,
            'settled_accounted_amount_text' => $this->formatAmount(0),
            'reversed_count' => 0,
            'reversed_net_amount' => 0,
            'reversed_net_amount_text' => $this->formatAmount(0),
            'abnormal_count' => 0,
        ];

        foreach ($rows as $row) {
            $status = (int) $row->group_status;
            $count = (int) $row->count_value;
            $netAmount = (int) $row->net_amount_value;
            $accountedAmount = (int) $row->accounted_amount_value;

            if ($status === TradeConstant::SETTLEMENT_STATUS_PENDING) {
                $result['pending_count'] = $count;
                $result['pending_net_amount'] = $netAmount;
                $result['pending_net_amount_text'] = $this->formatAmount($netAmount);
            } elseif ($status === TradeConstant::SETTLEMENT_STATUS_SETTLED) {
                $result['settled_count'] = $count;
                $result['settled_accounted_amount'] = $accountedAmount;
                $result['settled_accounted_amount_text'] = $this->formatAmount($accountedAmount);
            } elseif ($status === TradeConstant::SETTLEMENT_STATUS_REVERSED) {
                $result['reversed_count'] = $count;
                $result['reversed_net_amount'] = $netAmount;
                $result['reversed_net_amount_text'] = $this->formatAmount($netAmount);
            }
        }

        $result['abnormal_count'] = $result['pending_count'] + $result['reversed_count'];

        return $result;
    }

    /**
     * 按清算单号查询详情。
     *
     * @param string $settleNo 清算单号
     * @param int|null $merchantId 商户ID
     * @return SettlementOrder|null 清算订单模型
     */
    public function findBySettleNo(string $settleNo, ?int $merchantId = null): ?SettlementOrder
    {
        $row = $this->baseQuery($merchantId)
            ->where('s.settle_no', $settleNo)
            ->first();

        return $row ?: null;
    }

    /**
     * 查询清算订单详情。
     *
     * @param string $settleNo 清算单号
     * @param int|null $merchantId 商户ID
     * @return array{settlement_order: SettlementOrder, items: array, account_ledgers: \Illuminate\Support\Collection, timeline: array<int, array<string, mixed>>} 详情结构
     * @throws ValidationException
     * @throws ResourceNotFoundException
     */
    public function detail(string $settleNo, ?int $merchantId = null): array
    {
        $settleNo = trim($settleNo);
        if ($settleNo === '') {
            throw new ValidationException('settle_no 不能为空');
        }

        $settlementOrder = $this->findBySettleNo($settleNo, $merchantId);
        if (!$settlementOrder) {
            throw new ResourceNotFoundException('清算单不存在', ['settle_no' => $settleNo]);
        }

        $traceNo = trim((string) ($settlementOrder->trace_no ?: $settlementOrder->settle_no));
        $accountLedgers = $traceNo !== ''
            ? $this->merchantAccountLedgerRepository->listByTraceNo($traceNo)
            : collect();

        if ($accountLedgers->isEmpty()) {
            // 清算流水优先按追踪号查，缺失时回退到清算单号兜底。
            $accountLedgers = $this->merchantAccountLedgerRepository->listByBizNo((string) $settlementOrder->settle_no);
        }

        return [
            'settlement_order' => $settlementOrder,
            'items' => $this->settlementItemRepository->listBySettleNo($settleNo),
            'account_ledgers' => $accountLedgers,
            'timeline' => $this->buildTimeline($settlementOrder),
        ];
    }

    /**
     * 构建时间线。
     *
     * @param SettlementOrder|null $settlementOrder 结算订单
     * @return array<int, array<string, mixed>> 清算时间线
     */
    public function buildTimeline(?SettlementOrder $settlementOrder): array
    {
        if (!$settlementOrder) {
            return [];
        }

        // 清算时间线只展示真正走到过的节点，未发生的步骤不占位。
        return array_values(array_filter([
            [
                'title' => '生成清算单',
                'status' => 'finish',
                'at' => $this->formatDateTime($settlementOrder->generated_at ?? null),
            ],
            $settlementOrder->accounted_at ? [
                'title' => '入账处理',
                'status' => 'finish',
                'at' => $this->formatDateTime($settlementOrder->accounted_at ?? null),
            ] : null,
            $settlementOrder->completed_at ? [
                'title' => '清算完成',
                'status' => 'finish',
                'at' => $this->formatDateTime($settlementOrder->completed_at ?? null),
            ] : null,
            $settlementOrder->failed_at ? [
                'title' => '清算失败',
                'status' => 'error',
                'at' => $this->formatDateTime($settlementOrder->failed_at ?? null),
                'reason' => (string) ($settlementOrder->fail_reason ?? ''),
            ] : null,
        ]));
    }

    /**
     * 格式化单条记录。
     *
     * @param object $row 原始查询行
     * @return object 格式化后的记录
     */
    private function decorateRow(object $row): object
    {
        // 列表页直接要展示状态文案和金额文案，所以在查询层就把格式化字段补齐。
        $row->cycle_type_text = (string) (TradeConstant::settlementCycleMap()[(int) $row->cycle_type] ?? '未知');
        $row->status_text = (string) (TradeConstant::settlementStatusMap()[(int) $row->status] ?? '未知');
        $row->gross_amount_text = $this->formatAmount((int) $row->gross_amount);
        $row->fee_amount_text = $this->formatAmount((int) $row->fee_amount);
        $row->refund_amount_text = $this->formatAmount((int) $row->refund_amount);
        $row->fee_reverse_amount_text = $this->formatAmount((int) $row->fee_reverse_amount);
        $row->net_amount_text = $this->formatAmount((int) $row->net_amount);
        $row->accounted_amount_text = $this->formatAmount((int) $row->accounted_amount);
        $row->generated_at_text = $this->formatDateTime($row->generated_at ?? null);
        $row->accounted_at_text = $this->formatDateTime($row->accounted_at ?? null);
        $row->completed_at_text = $this->formatDateTime($row->completed_at ?? null);
        $row->failed_at_text = $this->formatDateTime($row->failed_at ?? null);
        $row->ext_json_text = $this->formatJson($row->ext_json ?? null);

        return $row;
    }

    /**
     * 统一构建查询。
     *
     * @param int|null $merchantId 商户ID
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function baseQuery(?int $merchantId = null)
    {
        $query = $this->settlementOrderRepository->query()
            ->from('ma_settlement_order as s')
            ->leftJoin('ma_merchant as m', 's.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->leftJoin('ma_payment_channel as c', 's.channel_id', '=', 'c.id')
            ->select([
                's.id',
                's.settle_no',
                's.trace_no',
                's.merchant_id',
                's.merchant_group_id',
                's.channel_id',
                's.cycle_type',
                's.cycle_key',
                's.status',
                's.gross_amount',
                's.fee_amount',
                's.refund_amount',
                's.fee_reverse_amount',
                's.net_amount',
                's.accounted_amount',
                's.generated_at',
                's.accounted_at',
                's.completed_at',
                's.failed_at',
                's.fail_reason',
                's.ext_json',
                's.created_at',
                's.updated_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name")
            ->selectRaw("COALESCE(c.name, '') AS channel_name");

        if ($merchantId !== null && $merchantId > 0) {
            $query->where('s.merchant_id', $merchantId);
        }

        return $query;
    }

    /**
     * 构建只用于筛选和统计的基础查询。
     *
     * @param int|null $merchantId 商户ID
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function baseFilterQuery(?int $merchantId = null)
    {
        $query = $this->settlementOrderRepository->query()
            ->from('ma_settlement_order as s')
            ->leftJoin('ma_merchant as m', 's.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->leftJoin('ma_payment_channel as c', 's.channel_id', '=', 'c.id');

        if ($merchantId !== null && $merchantId > 0) {
            $query->where('s.merchant_id', $merchantId);
        }

        return $query;
    }

    /**
     * 应用清算订单筛选条件。
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 查询构造器
     * @param array $filters 筛选条件
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    private function applyFilters($query, array $filters)
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            // 关键词同时命中清算单、追踪号、商户和通道，方便按任一线索回查批次。
            $query->where(function ($builder) use ($keyword) {
                $builder->where('s.settle_no', 'like', '%' . $keyword . '%')
                    ->orWhere('s.trace_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.name', 'like', '%' . $keyword . '%');
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

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('s.status', (int) $status);
        }

        $cycleType = (string) ($filters['cycle_type'] ?? '');
        if ($cycleType !== '') {
            $query->where('s.cycle_type', (int) $cycleType);
        }

        return $query;
    }

}
