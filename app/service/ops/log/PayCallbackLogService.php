<?php

namespace app\service\ops\log;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\model\admin\PayCallbackLog;
use app\repository\ops\log\PayCallbackLogRepository;

/**
 * 支付回调日志查询服务。
 */
class PayCallbackLogService extends BaseService
{
    /**
     * 构造函数，注入支付回调日志仓库。
     */
    public function __construct(
        protected PayCallbackLogRepository $payCallbackLogRepository
    ) {
    }

    /**
     * 分页查询支付回调日志。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('l.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('p.merchant_order_no', 'like', '%' . $keyword . '%')
                    ->orWhere('p.subject', 'like', '%' . $keyword . '%')
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
            $query->where('p.merchant_id', (int) $merchantId);
        }

        $channelId = (string) ($filters['channel_id'] ?? '');
        if ($channelId !== '') {
            $query->where('l.channel_id', (int) $channelId);
        }

        $callbackType = (string) ($filters['callback_type'] ?? '');
        if ($callbackType !== '') {
            $query->where('l.callback_type', (int) $callbackType);
        }

        $verifyStatus = (string) ($filters['verify_status'] ?? '');
        if ($verifyStatus !== '') {
            $query->where('l.verify_status', (int) $verifyStatus);
        }

        $processStatus = (string) ($filters['process_status'] ?? '');
        if ($processStatus !== '') {
            $query->where('l.process_status', (int) $processStatus);
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
     * 按 ID 查询详情。
     */
    public function findById(int $id): ?PayCallbackLog
    {
        $row = $this->baseQuery()
            ->where('l.id', $id)
            ->first();

        return $row ?: null;
    }

    /**
     * 格式化单条记录。
     */
    private function decorateRow(object $row): object
    {
        $row->callback_type_text = (string) (NotifyConstant::callbackTypeMap()[(int) $row->callback_type] ?? '未知');
        $row->verify_status_text = (string) (NotifyConstant::verifyStatusMap()[(int) $row->verify_status] ?? '未知');
        $row->process_status_text = (string) (NotifyConstant::processStatusMap()[(int) $row->process_status] ?? '未知');
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->request_data_text = $this->formatJson($row->request_data ?? null);
        $row->process_result_text = $this->formatJson($row->process_result ?? null);

        return $row;
    }

    /**
     * 构建基础查询。
     */
    private function baseQuery()
    {
        return $this->payCallbackLogRepository->query()
            ->from('ma_pay_callback_log as l')
            ->leftJoin('ma_pay_order as p', 'p.pay_no', '=', 'l.pay_no')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'p.merchant_id')
            ->leftJoin('ma_merchant_group as g', 'g.id', '=', 'm.group_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'l.channel_id')
            ->select([
                'l.id',
                'l.pay_no',
                'l.channel_id',
                'l.callback_type',
                'l.request_data',
                'l.verify_status',
                'l.process_status',
                'l.process_result',
                'l.created_at',
                'p.merchant_id',
                'p.merchant_order_no',
                'p.subject',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name")
            ->selectRaw("COALESCE(c.name, '') AS channel_name")
            ->selectRaw("COALESCE(c.plugin_code, '') AS channel_plugin_code");
    }

}
