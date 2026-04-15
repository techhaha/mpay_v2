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
 */
class SettlementOrderQueryService extends BaseService
{
    /**
     * 构造函数，注入清算订单仓库。
     */
    public function __construct(
        protected SettlementOrderRepository $settlementOrderRepository,
        protected SettlementItemRepository $settlementItemRepository,
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository
    ) {
    }

    /**
     * 分页查询清算订单。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null)
    {
        $query = $this->baseQuery($merchantId);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
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

        $paginator = $query
            ->orderByDesc('s.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRow($row);
        });

        return $paginator;
    }

    /**
     * 按清算单号查询详情。
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
     */
    public function buildTimeline(?SettlementOrder $settlementOrder): array
    {
        if (!$settlementOrder) {
            return [];
        }

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
     */
    private function decorateRow(object $row): object
    {
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

}
