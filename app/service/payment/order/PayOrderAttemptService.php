<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\BusinessStateException;
use app\exception\ConflictException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\merchant\MerchantService;
use app\service\payment\identity\PaymentIdentityService;
use app\service\payment\runtime\PaymentRouteService;

/**
 * 支付单发起服务。
 *
 * 负责商户校验、选路、业务单复用、支付单创建和首轮插件拉起。
 *
 * @property MerchantService $merchantService 商户服务
 * @property PaymentRouteService $paymentRouteService 支付路由服务
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 * @property PaymentIdentityService $paymentIdentityService 支付身份流程服务
 * @property PayOrderChannelDispatchService $payOrderChannelDispatchService 支付单渠道派发服务
 */
class PayOrderAttemptService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @param PaymentRouteService $paymentRouteService 支付路由服务
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @param PaymentIdentityService $paymentIdentityService 支付身份流程服务
     * @param PayOrderChannelDispatchService $payOrderChannelDispatchService 支付单渠道派发服务
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected PaymentRouteService $paymentRouteService,
        protected MerchantAccountService $merchantAccountService,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PaymentIdentityService $paymentIdentityService,
        protected PayOrderChannelDispatchService $payOrderChannelDispatchService
    ) {}

    /**
     * 预创建支付尝试。
     *
     * @param array $input 支付预创建参数
     * @return array 发起结果
     * @throws ValidationException
     * @throws BusinessStateException
     * @throws ConflictException
     */
    public function preparePayAttempt(array $input): array
    {
        $merchantId = (int) $input['merchant_id'];
        $payTypeId = (int) $input['pay_type_id'];
        $payAmount = (int) $input['pay_amount'];
        $this->assertPayAmountAllowed($payAmount);
        [$merchant, $merchantGroupId] = $this->resolveMerchantContext($merchantId);

        $this->ensurePaymentTypeEnabled($payTypeId);
        $route = $this->paymentRouteService->resolveByMerchantGroup($merchantGroupId, $payTypeId, $payAmount, $input);
        /** @var PaymentChannel $channel */
        $channel = $route['selected_channel']['channel'];
        $identityFlow = $this->paymentIdentityService->inspect($input, $merchant, $channel, $route);
        if ($identityFlow !== null) {
            return $identityFlow;
        }

        return $this->createAndDispatchPayAttempt(
            $input,
            $merchant,
            $merchantGroupId,
            $channel,
            (int) ($route['poll_group']->id ?? 0),
            $route
        );
    }

    /**
     * 预创建指定通道支付尝试。
     *
     * 后台通道测试不参与商户路由选择，但仍走真实订单创建和插件拉起链路。
     *
     * @param array $input 支付预创建参数
     * @param PaymentChannel $channel 指定支付通道
     * @return array 发起结果
     * @throws ValidationException
     * @throws BusinessStateException
     * @throws ConflictException
     */
    public function preparePayAttemptByChannel(array $input, PaymentChannel $channel): array
    {
        $merchantId = (int) $input['merchant_id'];
        $payTypeId = (int) $input['pay_type_id'];
        $payAmount = (int) $input['pay_amount'];
        $this->assertPayAmountAllowed($payAmount);
        [$merchant, $merchantGroupId] = $this->resolveDirectMerchantContext($merchantId);

        if ((int) $channel->pay_type_id !== $payTypeId) {
            throw new ValidationException('指定通道与支付方式不匹配', [
                'channel_id' => (int) $channel->id,
                'channel_pay_type_id' => (int) $channel->pay_type_id,
                'pay_type_id' => $payTypeId,
            ]);
        }

        $this->ensurePaymentTypeEnabled($payTypeId);

        $route = [
            'poll_group' => null,
            'candidates' => [],
            'selected_channel' => [
                'channel' => $channel,
                'direct' => true,
            ],
        ];
        $identityFlow = $this->paymentIdentityService->inspect($input, $merchant, $channel, $route);
        if ($identityFlow !== null) {
            return $identityFlow;
        }

        return $this->createAndDispatchPayAttempt($input, $merchant, $merchantGroupId, $channel, 0, $route);
    }

    /**
     * 预创建收银台业务单。
     *
     * 该方法只创建或复用业务单，不选路、不创建支付单。
     *
     * @param array $input 收银台参数
     * @return array 发起结果
     * @throws ValidationException
     * @throws BusinessStateException
     * @throws ConflictException
     */
    public function prepareCashierBizOrder(array $input): array
    {
        $merchantId = (int) $input['merchant_id'];
        $merchantOrderNo = trim((string) $input['merchant_order_no']);
        $payAmount = (int) $input['pay_amount'];
        $this->assertPayAmountAllowed($payAmount);
        [$merchant] = $this->resolveMerchantContext($merchantId);
        $bizFields = $input;
        $bizFields['ext_json'] = (array) ($bizFields['ext_json'] ?? []);
        unset($bizFields['ext_json']['payment'], $bizFields['ext_json']['presentation']);

        $bizOrder = $this->transactionRetry(function () use ($merchantId, $merchantOrderNo, $payAmount, $bizFields) {
            return $this->prepareCashierBizOrderInCurrentTransaction($merchantId, $merchantOrderNo, $payAmount, $bizFields);
        });

        return [
            'merchant' => $merchant,
            'biz_order' => $bizOrder,
            'cashier_url' => $this->buildCashierPageUrl((string) $bizOrder->biz_no),
        ];
    }

    /**
     * 创建支付单并拉起支付插件。
     *
     * @param array $input 支付预创建参数
     * @param Merchant $merchant 商户模型
     * @param int $merchantGroupId 商户分组ID快照
     * @param PaymentChannel $channel 已选支付通道
     * @param int $pollGroupId 轮询组ID快照，指定通道测试时为 0
     * @param array $route 路由上下文
     * @return array 发起结果
     */
    private function createAndDispatchPayAttempt(
        array $input,
        Merchant $merchant,
        int $merchantGroupId,
        PaymentChannel $channel,
        int $pollGroupId,
        array $route
    ): array {
        $merchantId = (int) $input['merchant_id'];
        $merchantOrderNo = trim((string) $input['merchant_order_no']);
        $payAmount = (int) $input['pay_amount'];
        $bizFields = $input;
        $bizFields['ext_json'] = (array) ($bizFields['ext_json'] ?? []);
        unset($bizFields['ext_json']['payment'], $bizFields['ext_json']['presentation']);

        $prepared = $this->transactionRetry(function () use (
            $input,
            $merchant,
            $merchantId,
            $merchantGroupId,
            $merchantOrderNo,
            $payAmount,
            $channel,
            $pollGroupId,
            $route,
            $bizFields
        ) {
            $expireAt = $this->resolvePayOrderExpireAt();
            $bizContext = $this->preparePayAttemptBizOrder(
                $merchantId,
                $merchantOrderNo,
                $payAmount,
                $bizFields,
                $expireAt
            );

            /** @var BizOrder $bizOrder */
            $bizOrder = $bizContext['biz_order'];
            $payNo = $this->generateNo('PAY');
            $payOrder = $this->createPayOrderForAttempt(
                $input,
                $bizOrder,
                (string) $bizContext['trace_no'],
                (int) $bizContext['attempt_no'],
                $merchantGroupId,
                $channel,
                $pollGroupId,
                $payNo,
                $expireAt
            );

            $this->markBizOrderPaying($bizOrder, $payOrder, (int) $bizContext['attempt_no']);

            return [
                'merchant' => $merchant,
                'biz_order' => $bizOrder->refresh(),
                'pay_order' => $payOrder,
                'route' => $route,
            ];
        });

        /** @var PayOrder $payOrder */
        $payOrder = $prepared['pay_order'];
        /** @var BizOrder $bizOrder */
        $bizOrder = $prepared['biz_order'];
        $channelDispatchResult = $this->payOrderChannelDispatchService->dispatch($payOrder, $bizOrder, $channel, $merchant);

        $prepared['pay_order'] = $channelDispatchResult['pay_order'];
        $prepared['payment_result'] = $channelDispatchResult['payment_result'];
        $prepared['pay_params'] = $channelDispatchResult['pay_params'];

        return $prepared;
    }

    /**
     * 解析商户和商户分组。
     *
     * @param int $merchantId 商户ID
     * @return array{0: Merchant, 1: int} 商户和商户分组ID
     * @throws ValidationException
     */
    private function resolveMerchantContext(int $merchantId): array
    {
        $merchant = $this->merchantService->ensureMerchantPayEnabled($merchantId);
        $merchantGroupId = (int) $merchant->group_id;
        if ($merchantGroupId <= 0) {
            throw new ValidationException('商户未配置分组', ['merchant_id' => $merchantId]);
        }

        $this->merchantService->ensureMerchantGroupEnabled($merchantGroupId);

        return [$merchant, $merchantGroupId];
    }

    /**
     * 解析指定通道支付使用的商户上下文。
     *
     * 指定通道测试不依赖商户分组路由，商户没有分组时也允许创建支付单。
     *
     * @param int $merchantId 商户ID
     * @return array{0: Merchant, 1: int} 商户和商户分组ID
     */
    private function resolveDirectMerchantContext(int $merchantId): array
    {
        $merchant = $this->merchantService->ensureMerchantPayEnabled($merchantId);
        $merchantGroupId = (int) $merchant->group_id;

        if ($merchantGroupId > 0) {
            $this->merchantService->ensureMerchantGroupEnabled($merchantGroupId);
        }

        return [$merchant, $merchantGroupId];
    }

    /**
     * 确认支付方式可用。
     *
     * @param int $payTypeId 支付方式ID
     * @return void
     * @throws BusinessStateException
     */
    private function ensurePaymentTypeEnabled(int $payTypeId): void
    {
        $paymentType = $this->paymentTypeRepository->find($payTypeId);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new BusinessStateException('支付方式不支持', ['pay_type_id' => $payTypeId]);
        }
    }

    /**
     * 准备支付尝试使用的业务单。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @param int $payAmount 支付金额
     * @param array<string, mixed> $bizFields 业务单字段
     * @param string|null $expireAt 过期时间
     * @return array{biz_order: BizOrder, attempt_no: int, trace_no: string}
     */
    private function preparePayAttemptBizOrder(
        int $merchantId,
        string $merchantOrderNo,
        int $payAmount,
        array $bizFields,
        ?string $expireAt
    ): array {
        $bizOrder = $this->bizOrderRepository->findForUpdateByMerchantAndOrderNo($merchantId, $merchantOrderNo);
        if ($bizOrder) {
            $this->assertBizOrderReusable($bizOrder, $merchantId, $merchantOrderNo, $payAmount);
            $this->assertNoActivePayAttempt($bizOrder);
            $this->assertBizOrderConsistency($bizOrder, $bizFields);
            $attemptNo = (int) $bizOrder->attempt_count + 1;
            $this->assertPayAttemptAllowed($bizOrder, $attemptNo);

            return [
                'biz_order' => $bizOrder,
                'attempt_no' => $attemptNo,
                'trace_no' => (string) $bizOrder->trace_no,
            ];
        }

        $bizOrder = $this->createBizOrder($merchantId, $merchantOrderNo, $payAmount, $bizFields, $expireAt);

        return [
            'biz_order' => $bizOrder,
            'attempt_no' => 1,
            'trace_no' => (string) $bizOrder->trace_no,
        ];
    }

    /**
     * 在事务内创建或复用收银台业务单。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @param int $payAmount 支付金额
     * @param array<string, mixed> $bizFields 业务单字段
     * @return BizOrder 业务单
     */
    private function prepareCashierBizOrderInCurrentTransaction(
        int $merchantId,
        string $merchantOrderNo,
        int $payAmount,
        array $bizFields
    ): BizOrder {
        $bizOrder = $this->bizOrderRepository->findForUpdateByMerchantAndOrderNo($merchantId, $merchantOrderNo);
        if ($bizOrder) {
            $this->assertBizOrderReusable($bizOrder, $merchantId, $merchantOrderNo, $payAmount);
            $this->assertBizOrderConsistency($bizOrder, $bizFields);

            return $bizOrder->refresh();
        }

        return $this->createBizOrder(
            $merchantId,
            $merchantOrderNo,
            $payAmount,
            $bizFields,
            $this->resolvePayOrderExpireAt()
        )->refresh();
    }

    /**
     * 创建业务单。
     *
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @param int $payAmount 支付金额
     * @param array<string, mixed> $bizFields 业务单字段
     * @param string|null $expireAt 过期时间
     * @return BizOrder 业务单
     */
    private function createBizOrder(
        int $merchantId,
        string $merchantOrderNo,
        int $payAmount,
        array $bizFields,
        ?string $expireAt
    ): BizOrder {
        return $this->bizOrderRepository->create([
            'biz_no' => $this->generateNo('BIZ'),
            'trace_no' => $this->generateNo('TRC'),
            'merchant_id' => $merchantId,
            'merchant_order_no' => $merchantOrderNo,
            'subject' => $bizFields['subject'],
            'body' => $bizFields['body'],
            'notify_url' => $bizFields['notify_url'],
            'return_url' => $bizFields['return_url'],
            'client_ip' => $bizFields['client_ip'],
            'device' => $bizFields['device'],
            'order_amount' => $payAmount,
            'paid_amount' => 0,
            'refund_amount' => 0,
            'status' => TradeConstant::ORDER_STATUS_CREATED,
            'active_pay_no' => '',
            'attempt_count' => 0,
            'expire_at' => $expireAt,
            'ext_json' => $bizFields['ext_json'],
        ]);
    }

    /**
     * 创建支付单。
     *
     * @param array $input 支付预创建参数
     * @param BizOrder $bizOrder 业务单
     * @param string $traceNo 追踪号
     * @param int $attemptNo 尝试序号
     * @param int $merchantGroupId 商户分组ID
     * @param PaymentChannel $channel 支付通道
     * @param int $pollGroupId 轮询组ID
     * @param string $payNo 支付单号
     * @param string|null $expireAt 过期时间
     * @return PayOrder 支付单
     */
    private function createPayOrderForAttempt(
        array $input,
        BizOrder $bizOrder,
        string $traceNo,
        int $attemptNo,
        int $merchantGroupId,
        PaymentChannel $channel,
        int $pollGroupId,
        string $payNo,
        ?string $expireAt
    ): PayOrder {
        $merchantId = (int) $input['merchant_id'];
        $merchantOrderNo = trim((string) $input['merchant_order_no']);
        $payTypeId = (int) $input['pay_type_id'];
        $payAmount = (int) $input['pay_amount'];
        $payOrderExtJson = array_replace_recursive(
            (array) ($bizOrder->ext_json ?? []),
            (array) ($input['ext_json'] ?? [])
        );
        $splitRateBp = (int) $channel->split_rate_bp;
        $merchantShareAmount = $this->calculateAmountByBp($payAmount, $splitRateBp);
        $serviceFeeAmount = max(0, $payAmount - $merchantShareAmount);

        $this->freezeSelfChannelFee(
            $channel,
            $merchantId,
            $merchantOrderNo,
            $payTypeId,
            $payNo,
            $traceNo,
            $serviceFeeAmount
        );

        return $this->payOrderRepository->create([
            'pay_no' => $payNo,
            'biz_no' => (string) $bizOrder->biz_no,
            'trace_no' => $traceNo,
            'merchant_id' => $merchantId,
            'merchant_group_id' => $merchantGroupId,
            'poll_group_id' => $pollGroupId,
            'attempt_no' => $attemptNo,
            'channel_id' => (int) $channel->id,
            'pay_type_id' => $payTypeId,
            'plugin_code' => (string) $channel->plugin_code,
            'channel_type' => (int) $channel->channel_mode,
            'channel_mode' => (int) $channel->channel_mode,
            'pay_amount' => $payAmount,
            'notify_url' => (string) $input['notify_url'],
            'return_url' => (string) $input['return_url'],
            'client_ip' => (string) $input['client_ip'],
            'device' => (string) $input['device'],
            'split_rate_bp_snapshot' => $splitRateBp,
            'service_fee_amount' => $serviceFeeAmount,
            'status' => TradeConstant::ORDER_STATUS_PAYING,
            'service_fee_status' => (int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_SELF && $serviceFeeAmount > 0
                ? TradeConstant::SERVICE_FEE_STATUS_FROZEN
                : TradeConstant::SERVICE_FEE_STATUS_NONE,
            'settlement_status' => TradeConstant::SETTLEMENT_STATUS_NONE,
            'channel_request_no' => $this->generateNo('REQ'),
            'request_at' => $this->now(),
            'expire_at' => $expireAt,
            'callback_status' => NotifyConstant::PROCESS_STATUS_PENDING,
            'callback_times' => 0,
            'ext_json' => $payOrderExtJson,
        ]);
    }

    /**
     * 激活业务单上的当前支付尝试。
     *
     * @param BizOrder $bizOrder 业务单
     * @param PayOrder $payOrder 支付单
     * @param int $attemptNo 尝试序号
     * @return void
     */
    private function markBizOrderPaying(BizOrder $bizOrder, PayOrder $payOrder, int $attemptNo): void
    {
        $bizOrder->active_pay_no = (string) $payOrder->pay_no;
        $bizOrder->attempt_count = $attemptNo;
        $bizOrder->status = TradeConstant::ORDER_STATUS_PAYING;
        $bizOrder->save();
    }

    /**
     * 自收通道预冻结平台服务费。
     *
     * @param PaymentChannel $channel 支付通道
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @param int $payTypeId 支付方式ID
     * @param string $payNo 支付单号
     * @param string $traceNo 追踪号
     * @param int $serviceFeeAmount 平台服务费
     * @return void
     */
    private function freezeSelfChannelFee(
        PaymentChannel $channel,
        int $merchantId,
        string $merchantOrderNo,
        int $payTypeId,
        string $payNo,
        string $traceNo,
        int $serviceFeeAmount
    ): void {
        if ((int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_SELF || $serviceFeeAmount <= 0) {
            return;
        }

        $this->merchantAccountService->freezeAmountInCurrentTransaction(
            $merchantId,
            $serviceFeeAmount,
            $payNo,
            'PAY_FREEZE:' . $payNo,
            [
                'merchant_order_no' => $merchantOrderNo,
                'pay_type_id' => $payTypeId,
                'channel_id' => (int) $channel->id,
                'remark' => '自收通道服务费预占',
            ],
            $traceNo
        );
    }

    /**
     * 校验业务单可以继续发起支付。
     *
     * @param BizOrder $bizOrder 业务单
     * @param int $merchantId 商户ID
     * @param string $merchantOrderNo 商户订单号
     * @param int $payAmount 支付金额
     * @return void
     */
    private function assertBizOrderReusable(BizOrder $bizOrder, int $merchantId, string $merchantOrderNo, int $payAmount): void
    {
        if ((int) $bizOrder->order_amount !== $payAmount) {
            throw new ValidationException('同一商户订单号金额不一致', [
                'merchant_id' => $merchantId,
                'merchant_order_no' => $merchantOrderNo,
            ]);
        }

        if (in_array((int) $bizOrder->status, [
            TradeConstant::ORDER_STATUS_SUCCESS,
            TradeConstant::ORDER_STATUS_CLOSED,
            TradeConstant::ORDER_STATUS_TIMEOUT,
        ], true)) {
            throw new BusinessStateException('支付单状态不允许重复创建', [
                'biz_no' => (string) $bizOrder->biz_no,
                'status' => (int) $bizOrder->status,
            ]);
        }

        if ((int) $bizOrder->status === TradeConstant::ORDER_STATUS_FAILED
            && !$this->boolConfig('pay_order_failed_retry_enabled', true)
        ) {
            throw new BusinessStateException('支付失败后不允许重新发起支付', [
                'biz_no' => (string) $bizOrder->biz_no,
            ]);
        }
    }

    /**
     * 校验同一业务单的支付尝试次数。
     *
     * @param BizOrder $bizOrder 业务单
     * @param int $attemptNo 本次尝试序号
     * @return void
     */
    private function assertPayAttemptAllowed(BizOrder $bizOrder, int $attemptNo): void
    {
        if (!$this->boolConfig('pay_order_attempt_limit_enabled', true)) {
            return;
        }

        $limit = max(1, (int) sys_config('pay_order_attempt_limit', 5));
        if ($attemptNo <= $limit) {
            return;
        }

        throw new BusinessStateException('支付尝试次数已达上限', [
            'biz_no' => (string) $bizOrder->biz_no,
            'attempt_limit' => $limit,
        ]);
    }

    /**
     * 校验全局支付金额边界。
     *
     * @param int $payAmount 支付金额，单位分
     * @return void
     */
    private function assertPayAmountAllowed(int $payAmount): void
    {
        if (!$this->boolConfig('pay_order_amount_limit_enabled', false)) {
            return;
        }

        $minAmount = $this->moneyConfigToCents('pay_order_min_amount_yuan', 1);
        $maxAmount = $this->moneyConfigToCents('pay_order_max_amount_yuan', 0);

        if ($minAmount > 0 && $payAmount < $minAmount) {
            throw new ValidationException('支付金额低于系统最小限制', [
                'min_amount' => FormatHelper::amount($minAmount),
            ]);
        }

        if ($maxAmount > 0 && $payAmount > $maxAmount) {
            throw new ValidationException('支付金额高于系统最大限制', [
                'max_amount' => FormatHelper::amount($maxAmount),
            ]);
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
     * 读取元金额配置并转换为分。
     *
     * @param string $key 配置键
     * @param int $defaultCents 默认金额，单位分
     * @return int 金额，单位分
     */
    private function moneyConfigToCents(string $key, int $defaultCents): int
    {
        $money = trim((string) sys_config($key, FormatHelper::amount($defaultCents)));
        if ($money === '') {
            return $defaultCents;
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return $defaultCents;
        }

        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $integer) * 100 + (int) substr($fraction, 0, 2);
    }

    /**
     * 防止同一业务单并发创建多个支付中订单。
     *
     * @param BizOrder $bizOrder 业务单
     * @return void
     * @throws ConflictException
     */
    private function assertNoActivePayAttempt(BizOrder $bizOrder): void
    {
        if (empty($bizOrder->active_pay_no)) {
            return;
        }

        $activePayOrder = $this->payOrderRepository->findForUpdateByPayNo((string) $bizOrder->active_pay_no);
        if ($activePayOrder && in_array((int) $activePayOrder->status, [
            TradeConstant::ORDER_STATUS_CREATED,
            TradeConstant::ORDER_STATUS_PAYING,
        ], true)) {
            throw new ConflictException('重复请求', [
                'biz_no' => (string) $bizOrder->biz_no,
                'active_pay_no' => (string) $bizOrder->active_pay_no,
            ]);
        }
    }

    /**
     * 校验业务单关键字段是否与首次写入保持一致。
     *
     * @param BizOrder $bizOrder 业务单
     * @param array<string, mixed> $fields 当前请求整理后的字段
     * @return void
     * @throws ConflictException
     */
    private function assertBizOrderConsistency(BizOrder $bizOrder, array $fields): void
    {
        foreach (['subject', 'body', 'notify_url', 'return_url', 'client_ip', 'device'] as $field) {
            $current = trim((string) ($bizOrder->{$field} ?? ''));
            $incoming = trim((string) ($fields[$field] ?? ''));
            if ($current !== $incoming) {
                throw new ConflictException('商户订单信息不一致', [
                    'biz_no' => (string) $bizOrder->biz_no,
                    'field' => $field,
                ]);
            }
        }

        $currentExtJson = (array) ($bizOrder->ext_json ?? []);
        $incomingExtJson = (array) ($fields['ext_json'] ?? []);
        if ($currentExtJson != $incomingExtJson) {
            throw new ConflictException('商户订单扩展信息不一致', [
                'biz_no' => (string) $bizOrder->biz_no,
            ]);
        }
    }

    /**
     * 构建收银台跳转地址。
     *
     * @param string $bizNo 业务单号
     * @return string 收银台 URL
     */
    private function buildCashierPageUrl(string $bizNo): string
    {
        return (string) sys_config('site_url') . '/cashier/' . rawurlencode($bizNo);
    }

    /**
     * 根据后台配置解析支付单过期时间。
     *
     * @return string|null 过期时间，关闭超时时返回 null
     */
    private function resolvePayOrderExpireAt(): ?string
    {
        $enabled = in_array(
            strtolower(trim((string) sys_config('pay_order_timeout_enabled', '1'))),
            ['1', 'true', 'yes', 'on', 'enabled'],
            true
        );

        if (!$enabled) {
            return null;
        }

        $minutes = max(1, (int) sys_config('pay_order_expire_minutes', 30));

        return date('Y-m-d H:i:s', time() + $minutes * 60);
    }

    /**
     * 按基点计算金额。
     *
     * @param int $amount 金额（分）
     * @param int $bp 费率基点，`10000` 表示 100%
     * @return int 金额（分）
     */
    private function calculateAmountByBp(int $amount, int $bp): int
    {
        if ($amount <= 0 || $bp <= 0) {
            return 0;
        }

        return (int) floor($amount * $bp / 10000);
    }
}
