<?php

namespace app\service\payment\cashier;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\PaymentIdentityConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\merchant\MerchantService;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\identity\PaymentIdentityService;
use app\service\payment\order\PayOrderService;
use app\service\payment\runtime\MerchantNotifyDispatcherService;
use app\service\payment\runtime\PaymentRouteService;
use app\service\system\config\SystemPublicConfigService;
use support\Log;
use support\Request;
use support\Response;
use Throwable;
use Webman\Exception\BusinessException;

/**
 * 收银台服务。
 *
 * 负责构建收银台上下文、确认支付方式、承接身份授权并输出公开支付数据。
 * 支付单状态推进由订单生命周期服务负责，本服务不直接修改支付终态。
 */
class CashierService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @param PaymentTypeService $paymentTypeService 支付方式服务
     * @param PaymentRouteService $paymentRouteService 支付路由服务
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param BizOrderRepository $bizOrderRepository 业务单仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderService $payOrderService 支付单服务
     * @param PaymentIdentityService $paymentIdentityService 支付身份服务
     * @param MerchantNotifyDispatcherService $merchantNotifyDispatcherService 商户通知派发服务
     * @param SystemPublicConfigService $systemPublicConfigService 系统公开配置服务
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected PaymentTypeService $paymentTypeService,
        protected PaymentRouteService $paymentRouteService,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderService $payOrderService,
        protected PaymentIdentityService $paymentIdentityService,
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService,
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
                ['stat_date' => FormatHelper::timestamp(time(), 'Y-m-d'), 'merchant_id' => (int) $merchant->id]
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
     * @param array<string, mixed> $input 请求参数
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
            'identity_flow' => true,
        ]);

        return $this->formatConfirmAttempt($attempt, $bizOrder);
    }

    /**
     * 使用已获取的用户身份继续收银台支付。
     *
     * @param array<string, mixed> $input 请求参数
     * @return array<string, mixed> 支付发起结果
     */
    public function resumeIdentity(array $input): array
    {
        $this->assertCashierEnabled();

        $token = trim((string) ($input['token'] ?? $input[PaymentIdentityConstant::FIELD_RESUME_TOKEN] ?? ''));

        return $this->withIdentityClaim($token, function () use ($token, $input): array {
            $context = $this->paymentIdentityService->context($token);
            $identity = $this->paymentIdentityService->identityFromInput($input, $context);

            return $this->continueIdentityPayment($token, $context, $identity);
        });
    }

    /**
     * 查询支付身份承接页上下文。
     *
     * @param string $token 身份流程 token
     * @return array<string, mixed> 身份流程上下文
     */
    public function identityContext(string $token): array
    {
        $this->assertCashierEnabled();

        return $this->paymentIdentityService->publicContext($token);
    }

    /**
     * 微信网页授权回调后继续收银台支付。
     *
     * @param array<string, mixed> $input 回调参数
     * @return Response 跳转响应
     */
    public function wechatIdentityCallback(array $input): Response
    {
        $this->assertCashierEnabled();

        $token = trim((string) ($input['state'] ?? ''));
        $code = trim((string) ($input['code'] ?? ''));
        $context = null;

        try {
            return $this->withIdentityClaim($token, function () use ($token, $code, &$context): Response {
                $identity = $this->paymentIdentityService->wechatIdentity($token, $code);
                $context = $identity['context'];
                $result = $this->continueIdentityPayment($token, $identity['context'], $identity['identity']);

                return $this->noStoreResponse(
                    redirect((string) ($result['payment_page_url'] ?? $this->buildSiteUrl('/cashier')))
                );
            });
        } catch (Throwable $e) {
            return $this->identityCallbackFailure($token, 'wechat', $e, $context);
        }
    }

    /**
     * 支付宝生活号网页授权回调后继续收银台支付。
     *
     * @param array<string, mixed> $input 回调参数
     * @return Response 跳转响应
     */
    public function alipayIdentityCallback(array $input): Response
    {
        $this->assertCashierEnabled();

        $token = trim((string) ($input['state'] ?? ''));
        $authCode = trim((string) ($input['auth_code'] ?? ''));
        $context = null;

        try {
            return $this->withIdentityClaim($token, function () use ($token, $authCode, &$context): Response {
                $identity = $this->paymentIdentityService->alipayIdentity($token, $authCode);
                $context = $identity['context'];
                $result = $this->continueIdentityPayment($token, $identity['context'], $identity['identity']);

                return $this->noStoreResponse(
                    redirect((string) ($result['payment_page_url'] ?? $this->buildSiteUrl('/cashier')))
                );
            });
        } catch (Throwable $e) {
            return $this->identityCallbackFailure($token, 'alipay', $e, $context);
        }
    }

    /**
     * 云闪付 userAuth 回调后继续收银台支付。
     *
     * @param array<string, mixed> $input 回调参数
     * @return Response 跳转响应
     */
    public function unionpayIdentityCallback(array $input): Response
    {
        $this->assertCashierEnabled();

        $token = trim((string) ($input['state'] ?? ''));
        $context = null;

        try {
            return $this->withIdentityClaim($token, function () use ($token, $input, &$context): Response {
                $identity = $this->paymentIdentityService->unionpayIdentity($token, $input);
                $context = $identity['context'];
                $result = $this->continueIdentityPayment($token, $identity['context'], $identity['identity']);

                return $this->noStoreResponse(
                    redirect((string) ($result['payment_page_url'] ?? $this->buildSiteUrl('/cashier')))
                );
            });
        } catch (Throwable $e) {
            return $this->identityCallbackFailure($token, 'unionpay', $e, $context);
        }
    }

    /**
     * 按身份流程缓存上下文继续创建支付单。
     *
     * @param string $token 身份流程 token
     * @param array<string, mixed> $context 身份流程缓存上下文
     * @param array<string, string> $identity 用户身份字段
     * @return array<string, mixed> 支付发起结果
     */
    private function continueIdentityPayment(string $token, array $context, array $identity): array
    {
        $input = $this->paymentIdentityService->restoreInput($context, $identity);
        $channelId = (int) ($context['channel_id'] ?? 0);
        /** @var PaymentChannel|null $channel */
        $channel = $this->paymentChannelRepository->find($channelId);
        $merchantId = (int) ($input['merchant_id'] ?? 0);
        $isPlatformChannel = $channel
            && (int) $channel->merchant_id === 0
            && (int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_COLLECT;
        $isMerchantChannel = $channel
            && (int) $channel->merchant_id === $merchantId
            && (int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_SELF;
        if (!$channel
            || (int) $channel->status !== CommonConstant::STATUS_ENABLED
            || (int) $channel->pay_type_id !== (int) ($input['pay_type_id'] ?? 0)
            || (!$isPlatformChannel && !$isMerchantChannel)) {
            throw new ValidationException('身份流程对应的支付通道不可用', ['channel_id' => $channelId]);
        }

        $bizOrder = $this->bizOrderRepository->findByMerchantAndOrderNo(
            (int) $input['merchant_id'],
            (string) $input['merchant_order_no']
        );
        if ($bizOrder) {
            $activePayOrder = $this->resolveActivePayOrder($bizOrder);
            if ($activePayOrder && in_array((int) $activePayOrder->status, [
                TradeConstant::ORDER_STATUS_CREATED,
                TradeConstant::ORDER_STATUS_PAYING,
            ], true)) {
                throw new ValidationException('当前订单已有进行中的支付尝试');
            }
        }

        $attempt = $this->payOrderService->preparePayAttemptByChannel($input, $channel);
        try {
            $this->paymentIdentityService->forget($token);
        } catch (Throwable $e) {
            // 支付尝试已创建并返回；缓存清理失败不能覆盖承接地址或诱导重新下单。
            Log::warning(sprintf('[CashierIdentity] 身份缓存清理失败 pay_no=%s exception=%s error=%s',
                (string) $attempt['pay_order']->pay_no, $e::class, $e->getMessage()));
        }

        return $this->formatConfirmAttempt($attempt, $attempt['biz_order'] ?? $bizOrder);
    }

    /**
     * 在 token 独占锁内执行身份换取和支付续跑。
     *
     * 总是尝试释放锁；清理失败只记录诊断，原业务结果或异常继续返回。
     *
     * @template T
     * @param string $token 身份流程令牌
     * @param callable():T $callback
     * @return T
     */
    private function withIdentityClaim(string $token, callable $callback): mixed
    {
        $claim = $this->paymentIdentityService->claim($token);
        try {
            return $callback();
        } finally {
            try {
                $this->paymentIdentityService->releaseClaim($claim);
            } catch (Throwable $e) {
                // 锁清理故障只留诊断，不能替换主流程的结果或带 pay_no 的原异常。
                Log::warning(sprintf('[CashierIdentity] 身份锁释放失败 token=%s exception=%s error=%s',
                    substr(hash('sha256', $token), 0, 12), $e::class, $e->getMessage()));
            }
        }
    }

    /**
     * 为身份授权跳转设置禁止缓存的安全响应头。
     *
     * @param Response $response 原始跳转响应
     * @return Response 禁止缓存的跳转响应
     */
    private function noStoreResponse(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * 按授权或支付阶段返回失败承接地址。
     *
     * 获得身份前沿用授权失败页；身份已取得但没有支付单时进入支付入口错误页；
     * 异常带 pay_no 时回到该订单的承接页，不把支付失败误报为授权失败。
     *
     * @param string $token 身份流程令牌
     * @param string $provider 授权平台
     * @param Throwable $e 授权或续跑异常
     * @param array<string, mixed>|null $context 已成功获取身份的流程上下文，未取得身份时为 null
     * @return Response 禁止缓存的失败承接页跳转
     */
    private function identityCallbackFailure(string $token, string $provider, Throwable $e, ?array $context): Response
    {
        $payNo = $e instanceof BusinessException ? (string) ($e->getData()['pay_no'] ?? '') : '';
        if ($payNo !== '') {
            return $this->noStoreResponse(redirect($this->buildSiteUrl($this->buildPaymentPagePath($payNo))));
        }

        Log::warning(sprintf(
            '[CashierIdentity] provider=%s stage=%s token=%s error=%s',
            $provider,
            $context === null ? 'authorization' : 'payment',
            $token === '' ? 'missing' : substr(hash('sha256', $token), 0, 12),
            $e::class
        ));

        $path = '/cashier/identity/' . rawurlencode($token) . '?error=authorization_failed';
        if ($context !== null) {
            $query = [
                'msg' => $e instanceof BusinessException
                    ? $this->publicPaymentErrorMessage($e->getMessage())
                    : '支付处理异常，请稍后查询订单或联系商户',
                'code' => $e instanceof BusinessException ? (string) $e->getCode() : '40200',
                'pid' => (string) $context['input']['merchant_id'],
                'out_trade_no' => (string) $context['input']['merchant_order_no'],
            ];
            $path = '/payment/entry/error?' . http_build_query($query);
        }

        return $this->noStoreResponse(redirect($this->buildSiteUrl($path)));
    }

    /**
     * 格式化收银台确认支付结果。
     *
     * @param array<string, mixed> $attempt 支付尝试结果
     * @param BizOrder|null $bizOrder 业务单模型
     * @return array<string, mixed> 前端响应
     */
    private function formatConfirmAttempt(array $attempt, ?BizOrder $bizOrder): array
    {
        if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
            return [
                'biz_no' => (string) ($bizOrder->biz_no ?? ''),
                'status' => PaymentIdentityConstant::STATUS_REQUIRED,
                PaymentIdentityConstant::FIELD_REQUIRED => true,
                'message' => (string) ($attempt['message'] ?? '请先完成用户身份授权'),
                PaymentIdentityConstant::FIELD_RESUME_TOKEN => (string) ($attempt[PaymentIdentityConstant::FIELD_RESUME_TOKEN] ?? ''),
                PaymentIdentityConstant::FIELD_IDENTITY_URL => (string) ($attempt[PaymentIdentityConstant::FIELD_IDENTITY_URL] ?? ''),
                'expires_in' => max(0, (int) ($attempt['expires_in'] ?? 0)),
            ];
        }

        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $paymentResult = (array) ($attempt['payment_result'] ?? []);
        $presentation = (array) ($paymentResult['presentation'] ?? []);
        $payParams = (array) ($presentation['pay_params'] ?? []);
        unset($payParams['raw']);
        $paymentPagePath = $this->buildPaymentPagePath((string) $payOrder->pay_no);

        return [
            'biz_no' => (string) $bizOrder->biz_no,
            'trade_no' => (string) $payOrder->pay_no,
            'pay_type' => strtolower(trim((string) ($presentation['pay_page'] ?? ''))),
            'pay_info' => $payParams,
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
        $returnUrl = (int) $payOrder->status === TradeConstant::ORDER_STATUS_SUCCESS
            ? $this->merchantNotifyDispatcherService->buildPaySuccessReturnUrl($payOrder, $bizOrder)
            : (string) ($payOrder->return_url ?: $bizOrder->return_url);

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
                'return_url' => $returnUrl,
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
     * 校验收银台是否已开启。
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
     * 解析系统布尔配置。
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
     * 构建公开的收银台支付承接数据。
     *
     * 正常状态沿用支付单保存的 presentation；终态或快照缺失时根据本地订单事实生成只读承接数据。
     * 输出前只移除 pay_params 第一层 raw，不修改插件自定义嵌套参数。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed>
     */
    private function resolvePresentation(PayOrder $payOrder): array
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $presentation = (array) ($extJson['presentation'] ?? []);
        $status = (int) $payOrder->status;
        if (in_array($status, [
            TradeConstant::ORDER_STATUS_FAILED,
            TradeConstant::ORDER_STATUS_CLOSED,
            TradeConstant::ORDER_STATUS_TIMEOUT,
        ], true)) {
            $presentation = $this->buildOrderStatePresentation($payOrder);
        } elseif (trim((string) ($presentation['pay_page'] ?? '')) === '') {
            $presentation = $this->buildOrderStatePresentation($payOrder);
        }

        $payParams = (array) ($presentation['pay_params'] ?? []);
        unset($payParams['raw']);
        $payParams['server_time_timestamp'] = time();

        return [
            'pay_page' => (string) ($presentation['pay_page'] ?? ''),
            'pay_type' => (string) ($presentation['pay_type'] ?? ''),
            'pay_product' => (string) ($presentation['pay_product'] ?? ''),
            'pay_action' => (string) ($presentation['pay_action'] ?? ''),
            'pay_params' => $payParams,
        ];
    }

    /**
     * 根据本地订单事实生成只读承接数据。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed>
     */
    private function buildOrderStatePresentation(PayOrder $payOrder): array
    {
        $status = (int) $payOrder->status;
        if (in_array($status, [
            TradeConstant::ORDER_STATUS_CREATED,
            TradeConstant::ORDER_STATUS_PAYING,
        ], true)) {
            return [
                'pay_page' => 'page',
                'pay_params' => [
                    '_page' => 'paymentPending',
                    'description' => '支付结果正在确认中，请勿重复支付。',
                ],
            ];
        }

        if ($status === TradeConstant::ORDER_STATUS_FAILED) {
            return [
                'pay_page' => 'error',
                'pay_params' => [
                    'error_msg' => $this->publicPaymentErrorMessage((string) ($payOrder->channel_error_msg ?? '')),
                    'code' => $this->publicPaymentErrorCode((string) ($payOrder->channel_error_code ?? '')),
                ],
            ];
        }

        if ($status === TradeConstant::ORDER_STATUS_CLOSED) {
            return [
                'pay_page' => 'error',
                'pay_params' => ['error_msg' => '支付订单已关闭，请返回收银台重新发起支付。'],
            ];
        }

        if ($status === TradeConstant::ORDER_STATUS_TIMEOUT) {
            return [
                'pay_page' => 'error',
                'pay_params' => ['error_msg' => '支付订单已超时，请返回收银台重新发起支付。'],
            ];
        }

        return [];
    }

    /**
     * 生成可在公开收银台展示的支付错误信息。
     */
    private function publicPaymentErrorMessage(string $message): string
    {
        $message = $this->normalizePublicPaymentText($message, 240);

        return $message !== '' ? $message : '支付发起失败，请检查通道配置或稍后重试。';
    }

    /**
     * 生成可在公开收银台展示的渠道错误码。
     */
    private function publicPaymentErrorCode(string $code): string
    {
        return $this->normalizePublicPaymentText($code, 64);
    }

    /**
     * 清理渠道文本，避免 HTML、控制字符和超长内容进入公开页面。
     */
    private function normalizePublicPaymentText(string $value, int $maxBytes): string
    {
        $value = strip_tags($value);
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, $maxBytes, 'UTF-8')
            : substr($value, 0, $maxBytes);
    }

}
