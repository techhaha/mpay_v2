<?php

namespace app\service\ops\log;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\model\admin\ChannelNotifyLog;
use app\repository\ops\log\ChannelNotifyLogRepository;

/**
 * 渠道通知日志查询服务。
 */
class ChannelNotifyLogService extends BaseService
{
    /**
     * 构造函数，注入渠道通知日志仓库。
     */
    public function __construct(
        protected ChannelNotifyLogRepository $channelNotifyLogRepository
    ) {
    }

    /**
     * 分页查询渠道通知日志。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('n.notify_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.channel_request_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.channel_trade_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.last_error', 'like', '%' . $keyword . '%')
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
            $query->where('n.channel_id', (int) $channelId);
        }

        $notifyType = (string) ($filters['notify_type'] ?? '');
        if ($notifyType !== '') {
            $query->where('n.notify_type', (int) $notifyType);
        }

        $verifyStatus = (string) ($filters['verify_status'] ?? '');
        if ($verifyStatus !== '') {
            $query->where('n.verify_status', (int) $verifyStatus);
        }

        $processStatus = (string) ($filters['process_status'] ?? '');
        if ($processStatus !== '') {
            $query->where('n.process_status', (int) $processStatus);
        }

        $paginator = $query
            ->orderByDesc('n.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $paginator->getCollection()->transform(function ($row) {
            return $this->decorateRow($row);
        });

        return $paginator;
    }

    /**
     * 按 ID 查询详情。
     */
    public function findById(int $id): ?ChannelNotifyLog
    {
        $row = $this->baseQuery()
            ->where('n.id', $id)
            ->first();

        return $row ?: null;
    }

    /**
     * 格式化单条记录。
     */
    private function decorateRow(object $row): object
    {
        $row->notify_type_text = (string) (NotifyConstant::notifyTypeMap()[(int) $row->notify_type] ?? '未知');
        $row->verify_status_text = (string) (NotifyConstant::verifyStatusMap()[(int) $row->verify_status] ?? '未知');
        $row->process_status_text = (string) (NotifyConstant::processStatusMap()[(int) $row->process_status] ?? '未知');
        $row->next_retry_at_text = $this->formatDateTime($row->next_retry_at ?? null);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);
        $row->raw_payload_text = $this->formatJson($row->raw_payload ?? null);

        return $row;
    }

    /**
     * 构建基础查询。
     */
    private function baseQuery()
    {
        return $this->channelNotifyLogRepository->query()
            ->from('ma_channel_notify_log as n')
            ->leftJoin('ma_pay_order as p', 'p.pay_no', '=', 'n.pay_no')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'p.merchant_id')
            ->leftJoin('ma_merchant_group as g', 'g.id', '=', 'm.group_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'n.channel_id')
            ->select([
                'n.id',
                'n.notify_no',
                'n.channel_id',
                'n.notify_type',
                'n.biz_no',
                'n.pay_no',
                'n.channel_request_no',
                'n.channel_trade_no',
                'n.raw_payload',
                'n.verify_status',
                'n.process_status',
                'n.retry_count',
                'n.next_retry_at',
                'n.last_error',
                'n.created_at',
                'n.updated_at',
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
