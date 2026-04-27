<?php

namespace app\service\payment\cashier;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\merchant\MerchantService;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\order\PayOrderService;
use app\service\payment\order\PaymentOrderInputAssembler;
use app\service\payment\runtime\PaymentRouteService;
use support\Request;

/**
 * 收银台服务。
 *
 * 负责收银台上下文、可选支付方式和最终支付确认。
 */
class CashierService extends BaseService
{
    public function __construct(
        protected MerchantService $merchantService,
        protected PaymentTypeService $paymentTypeService,
        protected PaymentRouteService $paymentRouteService,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderService $payOrderService,
        protected PaymentOrderInputAssembler $orderInputAssembler
    ) {
    }

    /**
     * 查询收银台上下文。
     *
     * @param string $bizNo 业务单号
     * @return array<string, mixed>
     */
    public function context(string $bizNo): array
    {
        $bizNo = trim($bizNo);
        if ($bizNo === '') {
            throw new ValidationException('biz_no 不能为空');
        }

        $bizOrder = $this->bizOrderRepository->findByBizNo($bizNo);
        if (!$bizOrder) {
            throw new ResourceNotFoundException('业务单不存在', ['biz_no' => $bizNo]);
        }

        $merchant = $this->merchantService->ensureMerchantEnabled((int) $bizOrder->merchant_id);
        $this->merchantService->ensureMerchantGroupEnabled((int) $merchant->group_id);

        $activePayOrder = $this->resolveActivePayOrder($bizOrder);
        $paySwitchEnabled = (int) ($merchant->pay_status ?? CommonConstant::STATUS_ENABLED) === CommonConstant::STATUS_ENABLED;
        $canPay = $paySwitchEnabled && !in_array((int) $bizOrder->status, [
            TradeConstant::ORDER_STATUS_SUCCESS,
            TradeConstant::ORDER_STATUS_CLOSED,
            TradeConstant::ORDER_STATUS_TIMEOUT,
        ], true) && (!$activePayOrder || !in_array((int) $activePayOrder->status, [
            TradeConstant::ORDER_STATUS_CREATED,
            TradeConstant::ORDER_STATUS_PAYING,
        ], true));

        // 收银台首屏只做“展示 + 可选方式预览”，不在这里创建支付单。
        $availablePayTypes = $canPay
            ? $this->paymentRouteService->previewAvailablePayTypes(
                (int) $merchant->group_id,
                (int) $bizOrder->order_amount,
                ['stat_date' => FormatHelper::timestamp(time(), 'Y-m-d')]
            )
            : [];

        return [
            'biz_order' => $this->formatBizOrder($bizOrder),
            'merchant' => $this->formatMerchant($merchant),
            'active_pay_order' => $activePayOrder ? $this->formatActivePayOrder($activePayOrder) : null,
            'available_pay_types' => $availablePayTypes,
            'can_pay' => $canPay,
        ];
    }

    /**
     * 确认支付方式并创建支付单。
     *
     * @param array $input 请求参数
     * @param Request $request 请求对象
     * @return array<string, mixed>
     */
    public function confirm(array $input, Request $request): array
    {
        $bizNo = trim((string) ($input['biz_no'] ?? ''));
        $typeCode = trim((string) ($input['type'] ?? ''));
        if ($bizNo === '') {
            throw new ValidationException('biz_no 不能为空');
        }
        if ($typeCode === '') {
            throw new ValidationException('type 不能为空');
        }

        $bizOrder = $this->bizOrderRepository->findByBizNo($bizNo);
        if (!$bizOrder) {
            throw new ResourceNotFoundException('业务单不存在', ['biz_no' => $bizNo]);
        }

        // 先恢复业务单，再把用户选中的支付方式转成一次明确的支付尝试。
        $merchant = $this->merchantService->ensureMerchantPayEnabled((int) $bizOrder->merchant_id);
        $this->merchantService->ensureMerchantGroupEnabled((int) $merchant->group_id);
        $activePayOrder = $this->resolveActivePayOrder($bizOrder);
        if ($activePayOrder && in_array((int) $activePayOrder->status, [
            TradeConstant::ORDER_STATUS_CREATED,
            TradeConstant::ORDER_STATUS_PAYING,
        ], true)) {
            throw new ValidationException('当前订单已有进行中的支付尝试');
        }

        $paymentType = $this->paymentTypeService->findByCode($typeCode);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('支付方式不支持');
        }

        // 收银台确认阶段只认业务单快照，避免前端再次篡改订单展示字段。
        $orderFields = $this->orderInputAssembler->buildOrderFields(
            [],
            null,
            $bizOrder,
            (array) ($bizOrder->ext_json ?? [])
        );

        // BizOrder 作为收银台上下文的种子，PayOrder 才是真正的支付快照。
        $attempt = $this->payOrderService->preparePayAttempt([
            'merchant_id' => (int) $bizOrder->merchant_id,
            'merchant_order_no' => (string) $bizOrder->merchant_order_no,
            'pay_type_id' => (int) $paymentType->id,
            'pay_amount' => (int) $bizOrder->order_amount,
            'subject' => (string) $orderFields['subject'],
            'body' => (string) $orderFields['body'],
            'notify_url' => (string) $orderFields['notify_url'],
            'return_url' => (string) $orderFields['return_url'],
            'client_ip' => (string) $orderFields['client_ip'],
            'device' => (string) $orderFields['device'],
            'ext_json' => (array) $orderFields['ext_json'],
        ]);

        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $paymentResult = (array) ($attempt['payment_result'] ?? []);

        return [
            'biz_no' => (string) $bizOrder->biz_no,
            'trade_no' => (string) $payOrder->pay_no,
            'pay_type' => strtolower(trim((string) ($payParams['type'] ?? $paymentResult['pay_type'] ?? 'qrcode'))),
            'pay_info' => $payParams,
            'payment_result' => $paymentResult,
            'payment_page_path' => $this->buildPaymentPagePath((string) $payOrder->pay_no),
            'payment_page_url' => $this->buildPaymentPageUrl((string) $payOrder->pay_no),
        ];
    }

    /**
     * 查询支付页详情。
     *
     * @param string $payNo 支付单号
     * @return array<string, mixed>
     */
    public function payOrderDetail(string $payNo): array
    {
        return $this->payOrderService->detail($payNo);
    }

    /**
     * 解析当前业务单的活跃支付单。
     *
     * @param BizOrder $bizOrder 业务单
     * @return PayOrder|null 支付单
     */
    private function resolveActivePayOrder(BizOrder $bizOrder): ?PayOrder
    {
        $activePayNo = trim((string) ($bizOrder->active_pay_no ?? ''));
        if ($activePayNo === '') {
            return null;
        }

        return $this->payOrderRepository->findByPayNo($activePayNo);
    }

    /**
     * 格式化业务单。
     *
     * @param BizOrder $bizOrder 业务单
     * @return array<string, mixed>
     */
    private function formatBizOrder(BizOrder $bizOrder): array
    {
        $statusMap = TradeConstant::orderStatusMap();

        return [
            'biz_no' => (string) $bizOrder->biz_no,
            'trace_no' => (string) ($bizOrder->trace_no ?? ''),
            'merchant_order_no' => (string) $bizOrder->merchant_order_no,
            'subject' => (string) $bizOrder->subject,
            'body' => (string) ($bizOrder->body ?? ''),
            'notify_url' => (string) ($bizOrder->notify_url ?? ''),
            'return_url' => (string) ($bizOrder->return_url ?? ''),
            'client_ip' => (string) ($bizOrder->client_ip ?? ''),
            'device' => (string) ($bizOrder->device ?? ''),
            'order_amount' => (int) $bizOrder->order_amount,
            'order_amount_text' => FormatHelper::amount((int) $bizOrder->order_amount),
            'paid_amount' => (int) $bizOrder->paid_amount,
            'refund_amount' => (int) $bizOrder->refund_amount,
            'status' => (int) $bizOrder->status,
            'status_text' => (string) ($statusMap[(int) $bizOrder->status] ?? ''),
            'active_pay_no' => (string) ($bizOrder->active_pay_no ?? ''),
            'attempt_count' => (int) $bizOrder->attempt_count,
            'ext_json' => (array) ($bizOrder->ext_json ?? []),
            'created_at' => FormatHelper::dateTime($bizOrder->created_at),
            'updated_at' => FormatHelper::dateTime($bizOrder->updated_at),
        ];
    }

    /**
     * 格式化商户信息。
     *
     * @param Merchant $merchant 商户
     * @return array<string, mixed>
     */
    private function formatMerchant(Merchant $merchant): array
    {
        return [
            'merchant_id' => (int) $merchant->id,
            'merchant_no' => (string) ($merchant->merchant_no ?? ''),
            'merchant_name' => (string) ($merchant->merchant_name ?? ''),
            'merchant_short_name' => (string) ($merchant->merchant_short_name ?? ''),
            'status' => (int) $merchant->status,
            'pay_status' => (int) ($merchant->pay_status ?? 1),
            'settle_status' => (int) ($merchant->settle_status ?? 1),
            'settle_type' => (int) ($merchant->settle_type ?? 4),
        ];
    }

    /**
     * 格式化活跃支付单。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed>
     */
    private function formatActivePayOrder(PayOrder $payOrder): array
    {
        return [
            'pay_no' => (string) $payOrder->pay_no,
            'pay_type_code' => $this->paymentTypeService->resolveCodeById((int) $payOrder->pay_type_id),
            'pay_amount' => (int) $payOrder->pay_amount,
            'pay_amount_text' => FormatHelper::amount((int) $payOrder->pay_amount),
            'status' => (int) $payOrder->status,
            'created_at' => FormatHelper::dateTime($payOrder->created_at),
            'request_at' => FormatHelper::dateTime($payOrder->request_at),
            'payment_page_path' => $this->buildPaymentPagePath((string) $payOrder->pay_no),
            'payment_page_url' => $this->buildPaymentPageUrl((string) $payOrder->pay_no),
        ];
    }

    /**
     * 构建支付页路径。
     *
     * @param string $payNo 支付单号
     * @return string
     */
    private function buildPaymentPagePath(string $payNo): string
    {
        return '/payment/' . rawurlencode($payNo);
    }

    /**
     * 构建支付页完整地址。
     *
     * @param string $payNo 支付单号
     * @return string
     */
    private function buildPaymentPageUrl(string $payNo): string
    {
        return rtrim((string) sys_config('site_url'), '/') . $this->buildPaymentPagePath($payNo);
    }

}
