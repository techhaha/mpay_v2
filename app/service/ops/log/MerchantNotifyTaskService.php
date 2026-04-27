<?php

namespace app\service\ops\log;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\model\payment\NotifyTask;
use app\repository\payment\notify\NotifyTaskRepository;
use app\service\payment\runtime\MerchantNotifyDispatcherService;

/**
 * 商户通知任务查询服务。
 *
 * 负责后台查询通知任务、格式化展示字段以及手动重试。
 */
class MerchantNotifyTaskService extends BaseService
{
    public function __construct(
        protected NotifyTaskRepository $notifyTaskRepository,
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService
    ) {
    }

    /**
     * 分页查询商户通知任务。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10)
    {
        $query = $this->baseQuery();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('n.notify_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.event_type', 'like', '%' . $keyword . '%')
                    ->orWhere('n.ref_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('n.notify_url', 'like', '%' . $keyword . '%')
                    ->orWhere('n.last_response', 'like', '%' . $keyword . '%')
                    ->orWhere('bo.merchant_order_no', 'like', '%' . $keyword . '%')
                    ->orWhere('bo.subject', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%');
            });
        }

        $merchantId = (string) ($filters['merchant_id'] ?? '');
        if ($merchantId !== '') {
            $query->where('n.merchant_id', (int) $merchantId);
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('n.status', (int) $status);
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
     * 查询商户通知任务详情。
     *
     * @param string $notifyNo 通知号
     * @return NotifyTask|object|null
     */
    public function findByNotifyNo(string $notifyNo): mixed
    {
        $row = $this->baseQuery()
            ->where('n.notify_no', $notifyNo)
            ->first();

        return $row ? $this->decorateRow($row) : null;
    }

    /**
     * 手动重试通知任务。
     *
     * @param string $notifyNo 通知号
     * @return mixed
     */
    public function retry(string $notifyNo): mixed
    {
        $this->merchantNotifyDispatcherService->dispatchTask($notifyNo);

        return $this->findByNotifyNo($notifyNo);
    }

    /**
     * 格式化单条记录。
     *
     * @param object $row 原始查询对象
     * @return object
     */
    private function decorateRow(object $row): object
    {
        $row->event_type_text = (string) (NotifyConstant::eventTypeMap()[(string) $row->event_type] ?? (string) $row->event_type);
        $row->status_text = (string) (NotifyConstant::taskStatusMap()[(int) $row->status] ?? '未知');
        $row->notify_data_text = $this->formatJson($row->notify_data ?? null);
        $row->last_notify_at_text = $this->formatDateTime($row->last_notify_at ?? null);
        $row->next_retry_at_text = $this->formatDateTime($row->next_retry_at ?? null);
        $row->created_at_text = $this->formatDateTime($row->created_at ?? null);
        $row->updated_at_text = $this->formatDateTime($row->updated_at ?? null);

        return $row;
    }

    /**
     * 构建基础查询。
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function baseQuery()
    {
        return $this->notifyTaskRepository->query()
            ->from('ma_notify_task as n')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'n.merchant_id')
            ->leftJoin('ma_merchant_group as g', 'g.id', '=', 'n.merchant_group_id')
            ->leftJoin('ma_biz_order as bo', 'bo.biz_no', '=', 'n.biz_no')
            ->select([
                'n.id',
                'n.notify_no',
                'n.event_type',
                'n.ref_no',
                'n.merchant_id',
                'n.merchant_group_id',
                'n.biz_no',
                'n.pay_no',
                'n.notify_url',
                'n.notify_data',
                'n.status',
                'n.retry_count',
                'n.next_retry_at',
                'n.last_notify_at',
                'n.last_response',
                'n.created_at',
                'n.updated_at',
                'bo.merchant_order_no',
                'bo.subject',
            ])
            ->selectRaw("COALESCE(m.merchant_no, '') AS merchant_no")
            ->selectRaw("COALESCE(m.merchant_name, '') AS merchant_name")
            ->selectRaw("COALESCE(m.merchant_short_name, '') AS merchant_short_name")
            ->selectRaw("COALESCE(g.group_name, '') AS merchant_group_name");
    }
}
