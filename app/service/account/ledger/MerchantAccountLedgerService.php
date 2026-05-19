<?php

namespace app\service\account\ledger;

use app\common\base\BaseService;
use app\common\constant\LedgerConstant;
use app\model\merchant\MerchantAccountLedger;
use app\repository\account\ledger\MerchantAccountLedgerRepository;

/**
 * 商户账户流水查询服务。
 *
 * 负责商户账户流水的列表、详情和展示字段装配。
 *
 * @property MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
 */
class MerchantAccountLedgerService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
     * @return void
     */
    public function __construct(
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository
    ) {
    }

    /**
     * 分页查询账户流水。
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
     *
     * @param int $id 商户账户流水ID
     * @return MerchantAccountLedger|null 流水模型
     */
    public function findById(int $id): ?MerchantAccountLedger
    {
        $row = $this->baseQuery()
            ->where('l.id', $id)
            ->first();

        return $row ?: null;
    }

    /**
     * 导出账户流水 CSV。
     *
     * @param array $filters 筛选条件
     * @return \support\Response CSV 响应
     */
    public function exportCsv(array $filters = [])
    {
        $rows = $this->paginate($filters, 1, 5000)->items();
        $csvRows = [[
            '流水号',
            '商户号',
            '商户名称',
            '业务类型',
            '业务单号',
            '追踪号',
            '事件类型',
            '方向',
            '金额',
            '变动前可用',
            '变动后可用',
            '变动前冻结',
            '变动后冻结',
            '幂等键',
            '备注',
            '创建时间',
        ]];

        foreach ($rows as $row) {
            $csvRows[] = [
                (string) ($row->ledger_no ?? ''),
                (string) ($row->merchant_no ?? ''),
                (string) ($row->merchant_name ?? ''),
                (string) ($row->biz_type_text ?? ''),
                (string) ($row->biz_no ?? ''),
                (string) ($row->trace_no ?? ''),
                (string) ($row->event_type_text ?? ''),
                (string) ($row->direction_text ?? ''),
                (string) ($row->amount_text ?? ''),
                (string) ($row->available_before_text ?? ''),
                (string) ($row->available_after_text ?? ''),
                (string) ($row->frozen_before_text ?? ''),
                (string) ($row->frozen_after_text ?? ''),
                (string) ($row->idempotency_key ?? ''),
                (string) ($row->remark ?? ''),
                (string) ($row->created_at_text ?? ''),
            ];
        }

        return $this->csvResponse($csvRows, 'account-ledgers-' . date('YmdHis') . '.csv');
    }

    /**
     * 格式化记录。
     *
     * @param object $row 原始查询行
     * @return object 格式化后的记录
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

        return $row;
    }

    /**
     * 构建查询。
     *
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
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
                'l.created_at',
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

