<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\NotifyConstant;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentType;
use app\repository\account\ledger\MerchantAccountLedgerRepository;
use app\repository\ops\log\ChannelNotifyLogRepository;
use app\repository\ops\log\PayCallbackLogRepository;
use app\repository\ops\log\PayOrderOperationLogRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\notify\NotifyTaskRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;

/**
 * 支付单查询与展示拼装服务。
 *
 * 负责支付单列表、详情和筛选辅助数据的查询，不承载状态推进逻辑。
 *
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
 * @property ChannelNotifyLogRepository $channelNotifyLogRepository 渠道通知日志仓库
 * @property PayCallbackLogRepository $payCallbackLogRepository 支付回调日志仓库
 * @property PayOrderOperationLogRepository $operationLogRepository 支付单后台操作日志仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 * @property SettlementOrderRepository $settlementOrderRepository 清算单仓库
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 * @property NotifyTaskRepository $notifyTaskRepository 通知任务仓库
 * @property PayOrderReportService $payOrderReportService 支付单报表服务
 * @property PayOrderActionResolverService $payOrderActionResolverService 支付单操作项计算服务
 */
class PayOrderQueryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param MerchantAccountLedgerRepository $merchantAccountLedgerRepository 商户账户流水仓库
     * @param ChannelNotifyLogRepository $channelNotifyLogRepository 渠道通知日志仓库
     * @param PayCallbackLogRepository $payCallbackLogRepository 支付回调日志仓库
     * @param PayOrderOperationLogRepository $operationLogRepository 支付单后台操作日志仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param SettlementOrderRepository $settlementOrderRepository 清算单仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @param NotifyTaskRepository $notifyTaskRepository 通知任务仓库
     * @param PayOrderReportService $payOrderReportService 支付单报表服务
     * @param PayOrderActionResolverService $payOrderActionResolverService 支付单操作项计算服务
     * @return void
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository,
        protected ChannelNotifyLogRepository $channelNotifyLogRepository,
        protected PayCallbackLogRepository $payCallbackLogRepository,
        protected PayOrderOperationLogRepository $operationLogRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected NotifyTaskRepository $notifyTaskRepository,
        protected PayOrderReportService $payOrderReportService,
        protected PayOrderActionResolverService $payOrderActionResolverService
    ) {
    }

    /**
     * 分页查询支付订单列表。
     *
     * 后台和商户后台共用同一套查询逻辑，商户侧会额外限制当前商户 ID。
     * 返回值会同时带上支付方式选项，方便列表页直接渲染筛选器。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户ID
     * @param bool $includeActions 是否返回后台可操作项
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, size: int, pay_types: array<int, array{label: string, value: int}>} 支付订单列表结构
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null, bool $includeActions = false): array
    {
        $query = $this->buildPayOrderQuery($merchantId, $includeActions);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '' && ($searchField = trim((string) ($filters['search_field'] ?? ''))) !== '') {
            $this->applyPayOrderSearch($query, $searchField, $keyword);
        }

        if (($merchantFilter = (int) ($filters['merchant_id'] ?? 0)) > 0) {
            $query->where('po.merchant_id', $merchantFilter);
        }

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('po.pay_type_id', $payTypeId);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('po.status', (int) $filters['status']);
        }

        if (array_key_exists('channel_mode', $filters) && $filters['channel_mode'] !== '') {
            $query->where('po.channel_mode', (int) $filters['channel_mode']);
        }

        if (array_key_exists('callback_status', $filters) && $filters['callback_status'] !== '') {
            $query->where('po.callback_status', (int) $filters['callback_status']);
        }

        $paginator = $query
            ->orderByDesc('po.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $list = [];
        foreach ($paginator->items() as $item) {
            $list[] = $this->payOrderReportService->formatPayOrderRow($item->toArray());
        }
        if ($includeActions) {
            $list = $this->payOrderActionResolverService->resolveForRows($list);
        }

        return [
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'size' => $paginator->perPage(),
            'pay_types' => $this->payTypeOptions(),
        ];
    }

    /**
     * 查询支付订单详情。
     *
     * 返回支付单、业务单、时间线和资金流水，供管理后台与商户后台共用。
     *
     * @param string $payNo 支付单号
     * @param int|null $merchantId 商户ID
     * @param bool $includeActions 是否返回后台可操作项
     * @return array{pay_order: PayOrder, biz_order: \app\model\payment\BizOrder|null, pay_order_view: array<string, mixed>|null, timeline: array<int, array<string, mixed>>, account_ledgers: iterable, account_ledgers_view: array<int, array<string, mixed>>, notify_tasks: array<int, array<string, mixed>>, callback_logs: array<int, array<string, mixed>>} 支付详情结构
     * @throws ValidationException
     * @throws ResourceNotFoundException
     */
    public function detail(string $payNo, ?int $merchantId = null, bool $includeActions = false): array
    {
        $payNo = trim($payNo);
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        if ($merchantId !== null && $merchantId > 0 && (int) $payOrder->merchant_id !== $merchantId) {
            // 商户后台只允许看自己的单，归属不匹配时直接按不存在处理。
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $detailRow = $this->buildPayOrderQuery($merchantId, $includeActions)
            ->where('po.pay_no', $payNo)
            ->first();
        $accountLedgers = $this->loadPayLedgers($payOrder);
        $accountLedgerRows = [];
        foreach ($accountLedgers as $ledger) {
            $accountLedgerRows[] = $this->payOrderReportService->formatLedgerRow($ledger->toArray());
        }
        $notifyTasks = $this->loadNotifyTasks($payNo);
        $callbackLogs = $this->loadCallbackLogs($payNo);
        $channelQueryLogs = $this->loadChannelQueryLogs($payNo);
        $operationLogs = $this->loadOperationLogs($payNo);
        $timeline = $this->payOrderReportService->buildTroubleshootingTimeline(
            $payOrder,
            $bizOrder,
            $this->loadRefundOrders($payOrder),
            $this->loadSettlementOrders($payOrder),
            $accountLedgers->all(),
            $notifyTasks,
            $callbackLogs,
            $channelQueryLogs,
            $operationLogs
        );
        $payOrderView = $detailRow ? $this->payOrderReportService->formatPayOrderRow($detailRow->toArray()) : null;
        if ($includeActions && $payOrderView) {
            $payOrderView = $this->payOrderActionResolverService->resolveForRow($payOrderView);
        }

        return [
            'pay_order' => $payOrder,
            'biz_order' => $bizOrder,
            'pay_order_view' => $payOrderView,
            'actions' => $includeActions ? ($payOrderView['actions'] ?? []) : [],
            'enabled_actions' => $includeActions ? ($payOrderView['enabled_actions'] ?? []) : [],
            'timeline' => $timeline,
            'account_ledgers' => $accountLedgers,
            'account_ledgers_view' => $accountLedgerRows,
            'notify_tasks' => $notifyTasks,
            'callback_logs' => $callbackLogs,
            'channel_query_logs' => $channelQueryLogs,
            'operation_logs' => $operationLogs,
        ];
    }

    /**
     * 加载支付相关资金流水。
     *
     * 优先按追踪号查询，追踪号为空时回退到业务单号，避免漏掉关联流水。
     *
     * @param PayOrder $payOrder 支付订单
     * @return \Illuminate\Support\Collection 支付相关资金流水集合
     */
    private function loadPayLedgers(PayOrder $payOrder)
    {
        $traceNo = trim((string) ($payOrder->trace_no ?: $payOrder->biz_no));
        $ledgers = $traceNo !== ''
            ? $this->merchantAccountLedgerRepository->listByTraceNo($traceNo)
            : collect();

        if ($ledgers->isEmpty()) {
            // 追踪号没有命中时，回到业务单号继续兜底，避免早期单据漏掉资金流水。
            $ledgers = $this->merchantAccountLedgerRepository->listByBizNo((string) $payOrder->biz_no);
        }

        return $ledgers;
    }

    /**
     * 加载支付单关联退款单。
     *
     * @param PayOrder $payOrder 支付订单
     * @return array<int, \app\model\payment\RefundOrder>
     */
    private function loadRefundOrders(PayOrder $payOrder): array
    {
        $refundOrders = $this->refundOrderRepository->query()
            ->where('pay_no', (string) $payOrder->pay_no)
            ->orderByDesc('id')
            ->get()
            ->all();

        if (!empty($refundOrders)) {
            return $refundOrders;
        }

        $traceNo = trim((string) ($payOrder->trace_no ?: $payOrder->biz_no));
        if ($traceNo === '') {
            return [];
        }

        return $this->refundOrderRepository->listByTraceNo($traceNo)->all();
    }

    /**
     * 加载支付单关联清算单。
     *
     * @param PayOrder $payOrder 支付订单
     * @return array<int, \app\model\payment\SettlementOrder>
     */
    private function loadSettlementOrders(PayOrder $payOrder): array
    {
        $traceNo = trim((string) ($payOrder->trace_no ?: $payOrder->biz_no));
        if ($traceNo === '') {
            return [];
        }

        return $this->settlementOrderRepository->listByTraceNo($traceNo)->all();
    }

    /**
     * 查询支付单详情展示行，供列表与详情复用。
     *
     * @param int|null $merchantId 商户ID
     * @param bool $includeActionColumns 是否包含后台动作计算所需字段
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function buildPayOrderQuery(?int $merchantId = null, bool $includeActionColumns = false)
    {
        $query = $this->payOrderRepository->query()
            ->from('ma_pay_order as po')
            ->leftJoin('ma_biz_order as bo', 'bo.biz_no', '=', 'po.biz_no')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'po.merchant_id')
            ->leftJoin('ma_merchant_group as g', 'g.id', '=', 'po.merchant_group_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'po.channel_id')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'po.pay_type_id')
            ->select([
                'po.id',
                'po.pay_no',
                'po.biz_no',
                'po.trace_no',
                'po.merchant_id',
                'po.merchant_group_id',
                'po.poll_group_id',
                'po.attempt_no',
                'po.channel_id',
                'po.pay_type_id',
                'po.plugin_code',
                'po.channel_type',
                'po.channel_mode',
                'po.pay_amount',
                'po.split_rate_bp_snapshot',
                'po.service_fee_amount',
                'po.status',
                'po.service_fee_status',
                'po.settlement_status',
                'po.channel_request_no',
                'po.channel_order_no',
                'po.channel_trade_no',
                'po.channel_error_code',
                'po.channel_error_msg',
                'po.request_at',
                'po.paid_at',
                'po.expire_at',
                'po.closed_at',
                'po.failed_at',
                'po.timeout_at',
                'po.callback_status',
                'po.callback_times',
                'po.ext_json',
                'po.created_at',
                'po.updated_at',
                'bo.merchant_order_no',
                'bo.subject',
                'bo.body',
                'bo.order_amount as biz_order_amount',
                'bo.paid_amount as biz_paid_amount',
                'bo.refund_amount as biz_refund_amount',
                'bo.status as biz_status',
                'bo.active_pay_no',
                'bo.attempt_count as biz_attempt_count',
                'bo.expire_at as biz_expire_at',
                'bo.paid_at as biz_paid_at',
                'bo.closed_at as biz_closed_at',
                'bo.failed_at as biz_failed_at',
                'bo.timeout_at as biz_timeout_at',
                'bo.ext_json as biz_ext_json',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
                'g.group_name as merchant_group_name',
                'c.name as channel_name',
                'c.plugin_code as channel_plugin_code',
                't.code as pay_type_code',
                't.name as pay_type_name',
                't.icon as pay_type_icon',
            ])
            ->selectRaw("COALESCE((SELECT ff.freeze_no FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1), '') AS freeze_no")
            ->selectRaw("COALESCE((SELECT ff.freeze_type FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1), 0) AS freeze_type")
            ->selectRaw("COALESCE((SELECT ff.remaining_amount FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1), 0) AS freeze_remaining_amount")
            ->selectRaw("COALESCE((SELECT ff.reason FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1), '') AS freeze_reason")
            ->selectRaw("COALESCE((SELECT ff.admin_id FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1), 0) AS freeze_admin_id")
            ->selectRaw("(SELECT ff.available_at FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1) AS freeze_available_at")
            ->selectRaw("(SELECT ff.frozen_at FROM ma_merchant_fund_freeze ff WHERE ff.pay_no = po.pay_no AND ff.status = 1 AND ff.remaining_amount > 0 ORDER BY ff.id DESC LIMIT 1) AS frozen_at")
            ->selectRaw("'' AS unfreeze_reason")
            ->selectRaw("0 AS unfrozen_by")
            ->selectRaw("NULL AS unfrozen_at");

        if ($includeActionColumns) {
            // 通知地址只用于后台按钮判断，避免影响商户/API 侧原有列表输出面。
            $query->addSelect([
                'po.notify_url',
                'po.return_url',
                'bo.notify_url as biz_notify_url',
                'bo.return_url as biz_return_url',
            ]);
        }

        if ($merchantId !== null && $merchantId > 0) {
            $query->where('po.merchant_id', $merchantId);
        }

        return $query;
    }

    /**
     * 按指定订单字段做精确搜索，避免列表页退回多字段模糊扫描。
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 查询构造器
     * @param string $searchField 前端选择的搜索字段
     * @param string $keyword 搜索关键字
     * @return void
     */
    private function applyPayOrderSearch($query, string $searchField, string $keyword): void
    {
        $columnMap = [
            'pay_no' => 'po.pay_no',
            'biz_no' => 'po.biz_no',
            'trace_no' => 'po.trace_no',
            'merchant_order_no' => 'bo.merchant_order_no',
            'channel_request_no' => 'po.channel_request_no',
            'channel_order_no' => 'po.channel_order_no',
            'channel_trade_no' => 'po.channel_trade_no',
        ];

        $column = $columnMap[$searchField] ?? '';
        if ($column !== '') {
            $query->where($column, $keyword);
        }
    }

    /**
     * 加载并格式化通知任务列表。
     *
     * @param string $payNo 支付单号
     * @return array<int, array<string, mixed>>
     */
    private function loadNotifyTasks(string $payNo): array
    {
        $rows = [];
        foreach ($this->notifyTaskRepository->listByPayNo($payNo) as $task) {
            $rows[] = [
                'notify_no' => (string) $task->notify_no,
                'event_type' => (string) ($task->event_type ?? ''),
                'event_type_text' => (string) (NotifyConstant::eventTypeMap()[(string) ($task->event_type ?? '')] ?? ($task->event_type ?? '')),
                'ref_no' => (string) ($task->ref_no ?? ''),
                'notify_url' => (string) $task->notify_url,
                'status' => (int) $task->status,
                'status_text' => (string) (NotifyConstant::taskStatusMap()[(int) $task->status] ?? '未知'),
                'retry_count' => (int) $task->retry_count,
                'last_notify_at_text' => $this->formatDateTime($task->last_notify_at, '—'),
                'next_retry_at_text' => $this->formatDateTime($task->next_retry_at, '—'),
                'last_response' => (string) ($task->last_response ?? ''),
                'notify_data' => $this->maskSensitiveData((array) ($task->notify_data ?? [])),
                'created_at_text' => $this->formatDateTime($task->created_at, '—'),
                'updated_at_text' => $this->formatDateTime($task->updated_at, '—'),
            ];
        }

        return $rows;
    }

    /**
     * 加载并格式化支付回调日志。
     *
     * @param string $payNo 支付单号
     * @return array<int, array<string, mixed>>
     */
    private function loadCallbackLogs(string $payNo): array
    {
        $rows = [];
        foreach ($this->payCallbackLogRepository->listByPayNo($payNo) as $log) {
            $rows[] = [
                'id' => (int) $log->id,
                'pay_no' => (string) $log->pay_no,
                'channel_id' => (int) $log->channel_id,
                'callback_type' => (int) $log->callback_type,
                'callback_type_text' => (string) (NotifyConstant::callbackTypeMap()[(int) $log->callback_type] ?? '未知'),
                'request_hash' => (string) ($log->request_hash ?? ''),
                'verify_status' => (int) $log->verify_status,
                'verify_status_text' => (string) (NotifyConstant::verifyStatusMap()[(int) $log->verify_status] ?? '未知'),
                'process_status' => (int) $log->process_status,
                'process_status_text' => (string) (NotifyConstant::processStatusMap()[(int) $log->process_status] ?? '未知'),
                'request_data' => $this->maskSensitiveData((array) ($log->request_data ?? [])),
                'process_result' => $this->maskSensitiveData((array) ($log->process_result ?? [])),
                'created_at_text' => $this->formatDateTime($log->created_at, '—'),
            ];
        }

        return $rows;
    }

    /**
     * 加载并格式化主动查单日志。
     *
     * @param string $payNo 支付单号
     * @return array<int, array<string, mixed>>
     */
    private function loadChannelQueryLogs(string $payNo): array
    {
        $rows = [];
        foreach ($this->channelNotifyLogRepository->listByPayNoAndType($payNo, NotifyConstant::NOTIFY_TYPE_QUERY) as $log) {
            $rows[] = [
                'id' => (int) $log->id,
                'notify_no' => (string) $log->notify_no,
                'channel_id' => (int) $log->channel_id,
                'pay_no' => (string) $log->pay_no,
                'biz_no' => (string) $log->biz_no,
                'channel_request_no' => (string) ($log->channel_request_no ?? ''),
                'channel_trade_no' => (string) ($log->channel_trade_no ?? ''),
                'raw_payload' => $this->maskSensitiveData((array) ($log->raw_payload ?? [])),
                'verify_status' => (int) $log->verify_status,
                'verify_status_text' => (string) (NotifyConstant::verifyStatusMap()[(int) $log->verify_status] ?? '未知'),
                'process_status' => (int) $log->process_status,
                'process_status_text' => (string) (NotifyConstant::processStatusMap()[(int) $log->process_status] ?? '未知'),
                'retry_count' => (int) $log->retry_count,
                'last_error' => (string) ($log->last_error ?? ''),
                'created_at_text' => $this->formatDateTime($log->created_at, '—'),
                'updated_at_text' => $this->formatDateTime($log->updated_at, '—'),
            ];
        }

        return $rows;
    }

    /**
     * 加载并格式化后台操作日志。
     *
     * @param string $payNo 支付单号
     * @return array<int, array<string, mixed>>
     */
    private function loadOperationLogs(string $payNo): array
    {
        $rows = [];
        foreach ($this->operationLogRepository->listByPayNo($payNo) as $log) {
            $rows[] = [
                'id' => (int) $log->id,
                'pay_no' => (string) $log->pay_no,
                'biz_no' => (string) $log->biz_no,
                'action' => (string) $log->action,
                'action_text' => $this->operationActionText((string) $log->action),
                'admin_id' => (int) $log->admin_id,
                'reason' => (string) ($log->reason ?? ''),
                'result_status' => (string) ($log->result_status ?? ''),
                'result_message' => (string) ($log->result_message ?? ''),
                'result_payload' => $this->maskSensitiveData((array) ($log->result_payload ?? [])),
                'created_at_text' => $this->formatDateTime($log->created_at, '—'),
            ];
        }

        return $rows;
    }

    /**
     * 后台操作码文本。
     *
     * @param string $action 操作码
     * @return string 操作名称
     */
    private function operationActionText(string $action): string
    {
        return [
            'renotify' => '重新通知',
            'active_query' => '主动查单',
            'api_refund' => 'API退款',
            'manual_refund' => '手动退款',
            'manual_success' => '手动补单',
            'freeze' => '冻结订单',
            'unfreeze' => '解冻订单',
        ][$action] ?? $action;
    }

    /**
     * 返回启用的支付方式选项，供列表筛选使用。
     *
     * @return array<int, array{label: string, value: int}> 支付方式选项
     */
    private function payTypeOptions(): array
    {
        return $this->paymentTypeRepository->query()
            ->where('status', CommonConstant::STATUS_ENABLED)
            ->orderBy('sort_no')
            ->orderByDesc('id')
            ->get(['id', 'name'])
            ->map(function (PaymentType $payType): array {
                return [
                    'label' => (string) $payType->name,
                    'value' => (int) $payType->id,
                ];
            })
            ->values()
            ->all();
    }

}
