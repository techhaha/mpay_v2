<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentType;
use app\repository\account\ledger\MerchantAccountLedgerRepository;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;

/**
 * 支付单查询服务。
 *
 * 只负责支付单列表类查询与展示格式化，不承载状态推进逻辑。
 */
class PayOrderQueryService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected MerchantAccountLedgerRepository $merchantAccountLedgerRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PayOrderReportService $payOrderReportService
    ) {
    }

    /**
     * 分页查询支付订单列表。
     *
     * 后台和商户后台共用同一套查询逻辑，商户侧会额外限制当前商户 ID。
     *
     * @param array $filters 查询条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户侧强制限定的商户 ID
     * @return array{list:array,total:int,page:int,size:int,pay_types:array}
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
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
                'po.fee_rate_bp_snapshot',
                'po.split_rate_bp_snapshot',
                'po.fee_estimated_amount',
                'po.fee_actual_amount',
                'po.status',
                'po.fee_status',
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
            ]);

        if ($merchantId !== null && $merchantId > 0) {
            $query->where('po.merchant_id', $merchantId);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('po.pay_no', 'like', '%' . $keyword . '%')
                    ->orWhere('po.biz_no', 'like', '%' . $keyword . '%')
                    ->orWhere('po.trace_no', 'like', '%' . $keyword . '%')
                    ->orWhere('po.channel_request_no', 'like', '%' . $keyword . '%')
                    ->orWhere('po.channel_order_no', 'like', '%' . $keyword . '%')
                    ->orWhere('po.channel_trade_no', 'like', '%' . $keyword . '%')
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
            $list[] = $this->payOrderReportService->formatPayOrderRow((array) $item);
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
     * @param string $payNo 支付单号
     * @param int|null $merchantId 商户侧强制限定的商户 ID
     * @return array{pay_order:mixed,biz_order:mixed,timeline:array,account_ledgers:mixed}
     */
    public function detail(string $payNo, ?int $merchantId = null): array
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
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $timeline = $this->payOrderReportService->buildPayTimeline($payOrder);
        $accountLedgers = $this->loadPayLedgers($payOrder);

        return [
            'pay_order' => $payOrder,
            'biz_order' => $bizOrder,
            'timeline' => $timeline,
            'account_ledgers' => $accountLedgers,
        ];
    }

    /**
     * 加载支付相关资金流水。
     */
    private function loadPayLedgers(PayOrder $payOrder)
    {
        $traceNo = trim((string) ($payOrder->trace_no ?: $payOrder->biz_no));
        $ledgers = $traceNo !== ''
            ? $this->merchantAccountLedgerRepository->listByTraceNo($traceNo)
            : collect();

        if ($ledgers->isEmpty()) {
            $ledgers = $this->merchantAccountLedgerRepository->listByBizNo((string) $payOrder->pay_no);
        }

        return $ledgers;
    }

    /**
     * 返回启用的支付方式选项，供列表筛选使用。
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
