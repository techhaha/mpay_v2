<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\repository\account\ledger\MerchantAccountLedgerRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\RefundOrderRepository;

/**
 * 退款单查询服务。
 *
 * 只负责退款列表、详情和数据查询，不承载退款状态推进逻辑。
 */
class RefundQueryService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected RefundReportService $refundReportService
    ) {
    }

    /**
     * 分页查询退款订单列表。
     *
     * @param array $filters 查询条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户侧强制限定的商户 ID
     * @return array{list:array,total:int,page:int,size:int,pay_types:array}
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
    {
        $query = $this->buildRefundOrderQuery($merchantId);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('ro.refund_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.trace_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.merchant_refund_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.channel_request_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.channel_refund_no', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.reason', 'like', '%' . $keyword . '%')
                    ->orWhere('ro.last_error', 'like', '%' . $keyword . '%')
                    ->orWhere('bo.merchant_order_no', 'like', '%' . $keyword . '%')
                    ->orWhere('bo.subject', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_no', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_name', 'like', '%' . $keyword . '%')
                    ->orWhere('m.merchant_short_name', 'like', '%' . $keyword . '%')
                    ->orWhere('g.group_name', 'like', '%' . $keyword . '%')
                    ->orWhere('c.name', 'like', '%' . $keyword . '%')
                    ->orWhere('t.name', 'like', '%' . $keyword . '%');
            });
        }

        if (($merchantFilter = (int) ($filters['merchant_id'] ?? 0)) > 0) {
            $query->where('ro.merchant_id', $merchantFilter);
        }

        if (($payTypeId = (int) ($filters['pay_type_id'] ?? 0)) > 0) {
            $query->where('po.pay_type_id', $payTypeId);
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== '') {
            $query->where('ro.status', (int) $filters['status']);
        }

        if (array_key_exists('channel_mode', $filters) && $filters['channel_mode'] !== '') {
            $query->where('po.channel_mode', (int) $filters['channel_mode']);
        }

        $paginator = $query
            ->orderByDesc('ro.id')
            ->paginate(max(1, $pageSize), ['*'], 'page', max(1, $page));

        $list = [];
        foreach ($paginator->items() as $item) {
            $list[] = $this->refundReportService->formatRefundOrderRow((array) $item);
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
     * 查询退款订单详情。
     *
     * @param string $refundNo 退款单号
     * @param int|null $merchantId 商户侧强制限定的商户 ID
     * @return array{refund_order:array,timeline:array,account_ledgers:array}
     */
    public function detail(string $refundNo, ?int $merchantId = null): array
    {
        $refundNo = trim($refundNo);
        if ($refundNo === '') {
            throw new ValidationException('refund_no 不能为空');
        }

        $query = $this->buildRefundOrderQuery($merchantId);
        $row = $query->where('ro.refund_no', $refundNo)->first();
        if (!$row) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $refundOrder = $this->refundReportService->formatRefundOrderRow((array) $row);
        $timeline = $this->refundReportService->buildRefundTimeline($row);
        $accountLedgers = $this->loadRefundLedgers($row);

        return [
            'refund_order' => $refundOrder,
            'timeline' => $timeline,
            'account_ledgers' => $accountLedgers,
        ];
    }

    /**
     * 按退款单号查询退款单，可按商户限制。
     */
    public function findByRefundNo(string $refundNo, ?int $merchantId = null): ?\app\model\payment\RefundOrder
    {
        $refundNo = trim($refundNo);
        if ($refundNo === '') {
            throw new ValidationException('refund_no 不能为空');
        }

        $query = $this->refundOrderRepository->query()
            ->from('ma_refund_order as ro')
            ->select(['ro.*'])
            ->where('ro.refund_no', $refundNo);

        if ($merchantId !== null && $merchantId > 0) {
            $query->where('ro.merchant_id', $merchantId);
        }

        $row = $query->first();
        if (!$row) {
            return null;
        }

        return $row;
    }

    /**
     * 构建退款订单基础查询，列表与详情共用。
     */
    private function buildRefundOrderQuery(?int $merchantId = null)
    {
        $query = $this->refundOrderRepository->query()
            ->from('ma_refund_order as ro')
            ->leftJoin('ma_pay_order as po', 'po.pay_no', '=', 'ro.pay_no')
            ->leftJoin('ma_biz_order as bo', 'bo.biz_no', '=', 'ro.biz_no')
            ->leftJoin('ma_merchant as m', 'm.id', '=', 'ro.merchant_id')
            ->leftJoin('ma_merchant_group as g', 'g.id', '=', 'ro.merchant_group_id')
            ->leftJoin('ma_payment_channel as c', 'c.id', '=', 'ro.channel_id')
            ->leftJoin('ma_payment_type as t', 't.id', '=', 'po.pay_type_id')
            ->select([
                'ro.id',
                'ro.refund_no',
                'ro.merchant_id',
                'ro.merchant_group_id',
                'ro.biz_no',
                'ro.trace_no',
                'ro.pay_no',
                'ro.merchant_refund_no',
                'ro.channel_id',
                'ro.refund_amount',
                'ro.fee_reverse_amount',
                'ro.status',
                'ro.channel_request_no',
                'ro.channel_refund_no',
                'ro.reason',
                'ro.request_at',
                'ro.processing_at',
                'ro.succeeded_at',
                'ro.failed_at',
                'ro.retry_count',
                'ro.last_error',
                'ro.ext_json',
                'ro.created_at',
                'ro.updated_at',
                'po.channel_mode',
                'po.channel_type',
                'po.pay_type_id',
                'po.pay_amount as pay_order_amount',
                'po.fee_actual_amount as pay_fee_actual_amount',
                'po.status as pay_status',
                'bo.merchant_order_no',
                'bo.subject',
                'bo.body',
                'bo.status as biz_status',
                'bo.order_amount as biz_order_amount',
                'bo.paid_amount as biz_paid_amount',
                'bo.refund_amount as biz_refund_amount',
                'm.merchant_no',
                'm.merchant_name',
                'm.merchant_short_name',
                'g.group_name as merchant_group_name',
                'c.name as channel_name',
                'c.plugin_code as channel_plugin_code',
                't.code as pay_type_code',
                't.name as pay_type_name',
                't.icon as pay_type_icon',
            ]);

        if ($merchantId !== null && $merchantId > 0) {
            $query->where('ro.merchant_id', $merchantId);
        }

        return $query;
    }

    /**
     * 加载退款相关资金流水。
     */
    private function loadRefundLedgers(mixed $refundOrder): array
    {
        $traceNo = trim((string) ($refundOrder->trace_no ?? ''));
        $bizNo = trim((string) ($refundOrder->biz_no ?? ''));
        $refundNo = trim((string) ($refundOrder->refund_no ?? ''));

        $ledgers = [];
        if ($traceNo !== '') {
            $ledgers = $this->collectionToArray($this->merchantAccountLedgerRepository->listByTraceNo($traceNo));
        }

        if (empty($ledgers) && $bizNo !== '') {
            $ledgers = $this->collectionToArray($this->merchantAccountLedgerRepository->listByBizNo($bizNo));
        }

        if (empty($ledgers) && $refundNo !== '') {
            $ledgers = $this->collectionToArray($this->merchantAccountLedgerRepository->listByBizNo($refundNo));
        }

        $rows = [];
        foreach ($ledgers as $ledger) {
            $rows[] = $this->refundReportService->formatLedgerRow((array) $ledger);
        }

        return $rows;
    }

    /**
     * 将查询结果转换成普通数组。
     */
    private function collectionToArray(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * 返回启用的支付方式选项，供筛选使用。
     */
    private function payTypeOptions(): array
    {
        return $this->paymentTypeRepository->enabledList(['id', 'name'])
            ->map(static function ($payType): array {
                return [
                    'label' => (string) $payType->name,
                    'value' => (int) $payType->id,
                ];
            })
            ->values()
            ->all();
    }
}
