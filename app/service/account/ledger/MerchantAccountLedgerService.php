<?php

namespace app\service\account\ledger;

use app\common\base\BaseService;
use app\common\constant\LedgerConstant;
use app\model\merchant\MerchantAccountLedger;
use app\repository\account\ledger\MerchantAccountLedgerRepository;

/**
 * 商户账户流水查询服务。
 */
class MerchantAccountLedgerService extends BaseService
{
    /**
     * 构造函数，注入流水仓库。
     */
    public function __construct(
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository
    ) {
    }

    /**
     * 分页查询账户流水。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('l.ledger_no', 'like', '%' . $keyword . '%')
                    ->orWhere('l.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('l.trace_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('l.idempotency_key', 'like', '%' . $keyword . '%');
            });
        }

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('l.merchant_id', (int) $merchantId);
        }

        $bizType = (string) ($filters['biz_type'] ?? '');
        if ($bizType !== '') {
            $query->where('l.biz_type', (int) $bizType);
        }

        $eventType = (string) ($filters['event_type'] ?? '');
        if ($eventType !== '') {
            $query->where('l.event_type', (int) $eventType);
        }

        $direction = (string) ($filters['direction'] ?? '');
        if ($direction !== '') {
            $query->where('l.direction', (int) $direction);
        }

        $paginator = $query
            ->orderByDesc('l.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRow($row);
        });

        return $paginator;
    }

    /**
     * 查询流水详情。
     */
    public function findById(int $id): ?MerchantAccountLedger
    {
        $row = $this->baseQuery()
            ->where('l.id', $id)
            ->first();

        return $row ?: null;
    }

    /**
     * 格式化记录。
     */
    private function decorateRow(object $row): object
    {
        $row->biz_type_text = (string) (LedgerConstant::bizTypeMap()[(int) $row->biz_type] ?? '未知');
        $row->event_type_text = (string) (LedgerConstant::eventTypeMap()[(int) $row->event_type] ?? '未知');
        $row->direction_text = (string) (LedgerConstant::directionMap()[(int) $row->direction] ?? '未知');
        $row->amount_text = $this->formatAmount((int) $row->amount);
        $row->available_before_text = $this->formatAmount((int) $row->available_before);
        $row->available_after_text = $this->formatAmount((int) $row->available_after);
        $row->frozen_before_text = $this->formatAmount((int) $row->frozen_before);
        $row->frozen_after_text = $this->formatAmount((int) $row->frozen_after);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->ext_json_text = $this->formatJson($row->ext_json ?? null);

        return $row;
    }

    /**
     * 构建查询。
     */
    private function baseQuery()
    {
        return $this->merchantAccountLedgerRepository->query()
            ->from('ma_merchant_account_ledger as l')
            ->leftJoin('ma_merchant as m', 'l.merchant_id', '=', 'm.id')
            ->leftJoin('ma_merchant_group as g', 'm.group_id', '=', 'g.id')
            ->select([
                'l.id',
                'l.ledger_no',
                'l.merchant_id',
                'l.biz_type',
                'l.biz_no',
                'l.trace_no',
                'l.event_type',
                'l.direction',
                'l.amount',
                'l.available_before',
                'l.available_after',
                'l.frozen_before',
                'l.frozen_after',
                'l.idempotency_key',
                'l.remark',
                'l.ext_json',
                'l.created_at',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name");
    }

}
