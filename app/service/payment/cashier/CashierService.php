<?php

namespace app\service\payment\cashier;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\BusinessStateException;
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
use app\service\payment\runtime\PaymentRouteService;
use app\service\system\config\SystemPublicConfigService;
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
        protected SystemPublicConfigService $systemPublicConfigService
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
        $this->assertCashierEnabled();

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
            'public_config' => $this->systemPublicConfigService->cashier(),
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
        $this->assertCashierEnabled();

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
        $attempt = $this->payOrderService->preparePayAttempt([
            'merchant_id' => (int) $bizOrder->merchant_id,
            'merchant_order_no' => (string) $bizOrder->merchant_order_no,
            'pay_type_id' => (int) $paymentType->id,
            'pay_amount' => (int) $bizOrder->order_amount,
            'subject' => (string) $bizOrder->subject,
            'body' => (string) $bizOrder->body,
            'notify_url' => (string) $bizOrder->notify_url,
            'return_url' => (string) $bizOrder->return_url,
            'client_ip' => (string) $bizOrder->client_ip,
            'device' => (string) $bizOrder->device,
            'ext_json' => (array) ($bizOrder->ext_json ?? []),
        ]);

        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $paymentResult = (array) ($attempt['payment_result'] ?? []);
        $paymentPagePath = $this->buildPaymentPagePath((string) $payOrder->pay_no);

        return [
            'biz_no' => (string) $bizOrder->biz_no,
            'trade_no' => (string) $payOrder->pay_no,
            'pay_type' => strtolower(trim((string) ($paymentResult['pay_page'] ?? 'qrcode'))),
            'pay_info' => $payParams,
            'payment_result' => $paymentResult,
            'payment_page_path' => $paymentPagePath,
            'payment_page_url' => $this->buildSiteUrl($paymentPagePath),
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
        $this->assertCashierEnabled();

        $payNo = trim($payNo);
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        if (!$bizOrder) {
            throw new ResourceNotFoundException('业务单不存在', ['biz_no' => (string) $payOrder->biz_no]);
        }

        $merchant = $this->merchantService->ensureMerchantEnabled((int) $payOrder->merchant_id);
        $paymentType = $this->paymentTypeService->findById((int) $payOrder->pay_type_id);
        $presentation = $this->resolvePresentation($payOrder);

        return [
            'order' => [
                'pay_no' => (string) $payOrder->pay_no,
                'biz_no' => (string) $payOrder->biz_no,
                'subject' => (string) $bizOrder->subject,
                'amount' => (int) $payOrder->pay_amount,
                'currency' => 'CNY',
                'status' => (int) $payOrder->status,
                'status_text' => (string) (TradeConstant::orderStatusMap()[(int) $payOrder->status] ?? ''),
                'created_at' => FormatHelper::dateTime($payOrder->request_at ?: $payOrder->created_at),
                'expire_at' => FormatHelper::dateTime($payOrder->expire_at),
                'updated_at' => FormatHelper::dateTime($payOrder->updated_at),
                'return_url' => (string) ($payOrder->return_url ?: $bizOrder->return_url),
            ],
            'merchant' => [
                'merchant_id' => (int) $merchant->id,
                'merchant_no' => (string) ($merchant->merchant_no ?? ''),
                'merchant_name' => (string) ($merchant->merchant_name ?? ''),
                'merchant_short_name' => (string) ($merchant->merchant_short_name ?? ''),
            ],
            'payment_type' => [
                'id' => (int) $payOrder->pay_type_id,
                'code' => (string) ($paymentType->code ?? ''),
                'name' => (string) ($paymentType->name ?? ''),
                'icon' => (string) ($paymentType->icon ?? ''),
            ],
            'presentation' => $presentation,
            'cashier_path' => $this->buildCashierPath((string) $payOrder->biz_no),
            'payment_path' => $this->buildPaymentPagePath((string) $payOrder->pay_no),
            'public_config' => $this->systemPublicConfigService->cashier(),
        ];
    }

    /**
     * 确认收银台已开启。
     *
     * @return void
     */
    private function assertCashierEnabled(): void
    {
        if (!$this->boolConfig('cashier_enabled', true)) {
            throw new BusinessStateException('收银台已关闭');
        }
    }

    /**
     * 读取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 布尔值
     */
    private function boolConfig(string $key, bool $default): bool
    {
        $value = strtolower(trim((string) sys_config($key, $default ? '1' : '0')));

        return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * 查询支付单状态。
     *
     * 状态轮询只查支付单表，避免反复构建支付详情 DTO 带来的多表查询开销。
     *
     * @param string $payNo 支付单号
     * @return array<string, mixed>
     */
    public function payOrderStatus(string $payNo): array
    {
        $this->assertCashierEnabled();

        $payNo = trim($payNo);
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no',
            'status',
            'paid_at',
            'closed_at',
            'failed_at',
            'timeout_at',
            'updated_at',
        ]);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        return [
            'pay_no' => (string) $payOrder->pay_no,
            'status' => (int) $payOrder->status,
            'status_text' => (string) (TradeConstant::orderStatusMap()[(int) $payOrder->status] ?? ''),
            'paid_at' => FormatHelper::dateTime($payOrder->paid_at),
            'closed_at' => FormatHelper::dateTime($payOrder->closed_at),
            'failed_at' => FormatHelper::dateTime($payOrder->failed_at),
            'timeout_at' => FormatHelper::dateTime($payOrder->timeout_at),
            'updated_at' => FormatHelper::dateTime($payOrder->updated_at),
        ];
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
        $paymentPagePath = $this->buildPaymentPagePath((string) $payOrder->pay_no);
        $paymentType = $this->paymentTypeService->findById((int) $payOrder->pay_type_id);

        return [
            'pay_no' => (string) $payOrder->pay_no,
            'pay_type_code' => (string) ($paymentType->code ?? ''),
            'pay_type_name' => (string) ($paymentType->name ?? ''),
            'pay_type_icon' => (string) ($paymentType->icon ?? ''),
            'pay_amount' => (int) $payOrder->pay_amount,
            'pay_amount_text' => FormatHelper::amount((int) $payOrder->pay_amount),
            'status' => (int) $payOrder->status,
            'created_at' => FormatHelper::dateTime($payOrder->created_at),
            'request_at' => FormatHelper::dateTime($payOrder->request_at),
            'payment_page_path' => $paymentPagePath,
            'payment_page_url' => $this->buildSiteUrl($paymentPagePath),
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
     * 构建业务单入口路径。
     *
     * @param string $bizNo 业务单号
     * @return string
     */
    private function buildCashierPath(string $bizNo): string
    {
        return '/cashier/' . rawurlencode($bizNo);
    }

    /**
     * 构建站点完整地址。
     *
     * @param string $path 站内路径
     * @return string
     */
    private function buildSiteUrl(string $path): string
    {
        return rtrim((string) sys_config('site_url'), '/') . $path;
    }

    /**
     * 提取收银台支付承接快照。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed>
     */
    private function resolvePresentation(PayOrder $payOrder): array
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $presentation = (array) ($extJson['presentation'] ?? []);
        $payParams = (array) ($presentation['pay_params'] ?? []);
        $payParams['server_time_timestamp'] = time();

        return [
            'pay_page' => (string) ($presentation['pay_page'] ?? ''),
            'pay_type' => (string) ($presentation['pay_type'] ?? ''),
            'pay_product' => (string) ($presentation['pay_product'] ?? ''),
            'pay_action' => (string) ($presentation['pay_action'] ?? ''),
            'pay_params' => $payParams,
        ];
    }

}
