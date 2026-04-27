<?php

namespace app\service\payment\epay;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\ConflictException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\merchant\credential\MerchantApiCredentialRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\merchant\MerchantService;
use app\service\payment\order\PayOrderQueryService;
use app\service\payment\order\PayOrderService;
use app\service\payment\order\PaymentOrderInputAssembler;
use app\service\payment\order\RefundQueryService;
use app\service\payment\order\RefundService;
use app\service\payment\transfer\TransferService;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\config\PaymentTypeService;
use support\Request;
use support\Response;
use Throwable;

/**
 * ePay V2 协议服务。
 */
class EpayV2ProtocolService extends BaseService
{
    public function __construct(
        protected MerchantService $merchantService,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderService $payOrderService,
        protected PayOrderQueryService $payOrderQueryService,
        protected RefundService $refundService,
        protected RefundQueryService $refundQueryService,
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountRepository $merchantAccountRepository,
        protected PaymentPluginManager $paymentPluginManager,
        protected TransferService $transferService,
        protected EpaySignerManager $signerManager,
        protected PaymentOrderInputAssembler $orderInputAssembler
    ) {
    }

    public function submit(array $payload, Request $request): Response
    {
        try {
            $typeCode = trim((string) ($payload['type'] ?? ''));
            if ($typeCode === '') {
                // `type` 为空时先回收银台，显式选完方式后再创建支付单。
                $attempt = $this->prepareCashierSubmit($payload, $request);
                $cashierUrl = (string) ($attempt['cashier_url'] ?? '');
                if ($cashierUrl === '') {
                    throw new ValidationException('收银台跳转地址生成失败');
                }

                return redirect($cashierUrl);
            }

            return $this->buildBrowserSubmitResponse($this->preparePayAttempt($payload, $request, false));
        } catch (Throwable $e) {
            return json($this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]));
        }
    }

    public function create(array $payload, Request $request): array
    {
        try {
            $attempt = $this->preparePayAttempt($payload, $request, true);
            return $this->signResponse($this->buildCreateResponse($attempt));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function query(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $context = $this->resolvePayOrderContext((int) $merchant->id, $payload);
            if (!$context) {
                throw new ResourceNotFoundException('订单不存在');
            }

            return $this->signResponse($this->buildOrderResponse($context['pay_order'], $context['biz_order']));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function refund(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $context = $this->resolvePayOrderContext((int) $merchant->id, $payload);
            if (!$context) {
                throw new ResourceNotFoundException('订单不存在');
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];
            $refundAmount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
            if ($refundAmount <= 0) {
                throw new ValidationException('money 参数不合法');
            }

            $merchantRefundNo = trim((string) ($payload['out_refund_no'] ?? $payload['refund_no'] ?? ''));
            $refundOrder = $this->refundService->createRefund([
                'pay_no' => (string) $payOrder->pay_no,
                'merchant_refund_no' => $merchantRefundNo,
                'refund_amount' => $refundAmount,
                'reason' => trim((string) ($payload['reason'] ?? '')),
            ]);

            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $pluginResult = $plugin->refund([
                'order_id' => (string) $payOrder->pay_no,
                'pay_no' => (string) $payOrder->pay_no,
                'biz_no' => (string) $payOrder->biz_no,
                'chan_order_no' => (string) $payOrder->channel_order_no,
                'chan_trade_no' => (string) $payOrder->channel_trade_no,
                'out_trade_no' => (string) $payOrder->channel_order_no,
                'refund_no' => (string) $refundOrder->refund_no,
                'refund_amount' => $refundAmount,
                'refund_reason' => trim((string) ($payload['reason'] ?? '')),
                'extra' => (array) ($payOrder->ext_json ?? []),
            ]);

            if (!$this->isPluginSuccess($pluginResult)) {
                $this->refundService->markRefundFailed((string) $refundOrder->refund_no, [
                    'failed_at' => $this->now(),
                    'last_error' => (string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '退款失败'),
                    'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
                ]);

                throw new ValidationException((string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '退款失败'));
            }

            $this->refundService->markRefundSuccess((string) $refundOrder->refund_no, [
                'succeeded_at' => $this->now(),
                'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
            ]);

            $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
            return $this->signResponse($this->buildRefundResponse($refundOrder->refresh(), $payOrder->refresh(), $bizOrder));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function refundQuery(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $refundOrder = $this->resolveRefundOrder((int) $merchant->id, $payload);
            $payOrder = $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
            $bizOrder = $this->bizOrderRepository->findByBizNo((string) $refundOrder->biz_no);

            return $this->signResponse($this->buildRefundResponse($refundOrder, $payOrder, $bizOrder));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function close(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $context = $this->resolvePayOrderContext((int) $merchant->id, $payload);
            if (!$context) {
                throw new ResourceNotFoundException('订单不存在');
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];
            $currentStatus = (int) $payOrder->status;
            if ($currentStatus === TradeConstant::ORDER_STATUS_CLOSED) {
                return $this->signResponse([
                    'code' => $this->successCode(),
                    'msg' => 'success',
                ]);
            }

            if ($currentStatus === TradeConstant::ORDER_STATUS_SUCCESS) {
                throw new ValidationException('订单已支付成功，不能关闭');
            }

            if (TradeConstant::isOrderTerminalStatus($currentStatus)) {
                throw new ValidationException('订单已结束，不能关闭');
            }

            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $pluginResult = $plugin->close([
                'order_id' => (string) $payOrder->pay_no,
                'pay_no' => (string) $payOrder->pay_no,
                'biz_no' => (string) $payOrder->biz_no,
                'chan_order_no' => (string) $payOrder->channel_order_no,
                'chan_trade_no' => (string) $payOrder->channel_trade_no,
                'out_trade_no' => (string) ($payOrder->channel_order_no ?: $payOrder->pay_no),
                'extra' => (array) ($payOrder->ext_json ?? []),
            ]);

            if (!$this->isPluginSuccess($pluginResult)) {
                throw new ValidationException((string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '渠道关单失败'));
            }

            $closeReason = (string) ($pluginResult['msg'] ?? 'ePay V2 手动关闭');
            $this->payOrderService->closePayOrder((string) $payOrder->pay_no, [
                'closed_at' => $this->now(),
                'reason' => $closeReason,
                'ext_json' => [
                    'plugin' => [
                        'close_result' => $pluginResult,
                    ],
                ],
            ]);

            return $this->signResponse([
                'code' => $this->successCode(),
                'msg' => 'success',
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function merchantInfo(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $account = $this->merchantAccountRepository->findByMerchantId((int) $merchant->id);
            $today = $this->nowDate();
            $yesterday = $this->yesterdayDate();

            $orderQuery = $this->payOrderRepository->query()->where('merchant_id', (int) $merchant->id);

            $totalOrders = (int) (clone $orderQuery)->count();
            $todayOrders = (int) (clone $orderQuery)->whereDate('created_at', $today)->count();
            $yesterdayOrders = (int) (clone $orderQuery)->whereDate('created_at', $yesterday)->count();
            $todayMoney = (int) (clone $orderQuery)->whereDate('created_at', $today)->sum('pay_amount');
            $yesterdayMoney = (int) (clone $orderQuery)->whereDate('created_at', $yesterday)->sum('pay_amount');

            return $this->signResponse([
                'code' => $this->successCode(),
                'msg' => 'success',
                'pid' => (int) $merchant->id,
                'status' => (int) $merchant->status,
                'pay_status' => (int) ($merchant->pay_status ?? 1),
                'settle_status' => (int) ($merchant->settle_status ?? 1),
                'money' => $this->formatAmount((int) ($account->available_balance ?? 0)),
                'settle_type' => (int) ($merchant->settle_type ?? 4),
                'settle_account' => (string) ($merchant->settlement_account_no ?? ''),
                'settle_name' => (string) ($merchant->settlement_account_name ?? ''),
                'order_num' => $totalOrders,
                'order_num_today' => $todayOrders,
                'order_num_lastday' => $yesterdayOrders,
                'order_money_today' => $this->formatAmount($todayMoney),
                'order_money_lastday' => $this->formatAmount($yesterdayMoney),
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function merchantOrders(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $limit = min(50, max(1, (int) ($payload['limit'] ?? 20)));
            $offset = max(0, (int) ($payload['offset'] ?? 0));
            $page = (int) floor($offset / $limit) + 1;
            $filters = [];
            if (array_key_exists('status', $payload) && $payload['status'] !== '') {
                $filters['status'] = (int) $payload['status'];
            }

            $result = $this->payOrderQueryService->paginate($filters, $page, $limit, (int) $merchant->id);

            return $this->signResponse([
                'code' => $this->successCode(),
                'msg' => 'success',
                'data' => $result['list'] ?? [],
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function transferSubmit(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $data = $this->transferService->submit($merchant, $payload);

            return $this->signResponse([
                'code' => $this->successCode(),
                'msg' => 'success',
                'status' => (int) ($data['status'] ?? 0),
                'biz_no' => (string) ($data['biz_no'] ?? ''),
                'out_biz_no' => (string) ($data['out_biz_no'] ?? ''),
                'orderid' => (string) ($data['orderid'] ?? ''),
                'paydate' => (string) ($data['paydate'] ?? ''),
                'cost_money' => (string) ($data['cost_money'] ?? ''),
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function transferQuery(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $data = $this->transferService->query($merchant, $payload);

            return $this->signResponse([
                'code' => $this->successCode(),
                'msg' => 'success',
            ] + $data);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    public function transferBalance(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload, true);
            $data = $this->transferService->balance($merchant);

            return $this->signResponse([
                'code' => $this->successCode(),
                'msg' => 'success',
            ] + $data);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => $this->resolveFailureCode($e),
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    /**
     * 预创建支付。
     *
     * @param array $payload 请求参数
     * @param Request $request 请求对象
     * @param bool $requireType 是否强制要求 type
     * @return array<string, mixed>
     */
    private function preparePayAttempt(array $payload, Request $request, bool $requireType): array
    {
        $merchant = $this->authorizeMerchant($payload, true);
        $typeCode = trim((string) ($payload['type'] ?? ''));
        if ($requireType && $typeCode === '') {
            throw new ValidationException('type 不能为空');
        }

        $paymentType = $this->resolvePaymentType($typeCode);
        $merchantOrderNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $subject = trim((string) ($payload['name'] ?? ''));
        $amount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
        if ($merchantOrderNo === '') {
            throw new ValidationException('out_trade_no 不能为空');
        }
        if ($subject === '') {
            throw new ValidationException('name 不能为空');
        }
        if ($amount <= 0) {
            throw new ValidationException('money 参数不合法');
        }

        // V2 直连支付和收银台确认共用同一套字段归一化逻辑。
        $orderFields = $this->orderInputAssembler->buildOrderFields($payload, $request, null, [
            '_protocol_version' => 'v2',
        ]);
        $normalized = [
            'merchant_id' => (int) $merchant->id,
            'merchant_order_no' => $merchantOrderNo,
            'pay_type_id' => (int) $paymentType->id,
            'pay_amount' => $amount,
            'subject' => (string) $orderFields['subject'],
            'body' => (string) $orderFields['body'],
            'notify_url' => (string) $orderFields['notify_url'],
            'return_url' => (string) $orderFields['return_url'],
            'client_ip' => (string) $orderFields['client_ip'],
            'device' => (string) $orderFields['device'],
            'channel_id' => (int) ($payload['channel_id'] ?? 0),
            'ext_json' => (array) $orderFields['ext_json'],
        ];

        $attempt = $this->payOrderService->preparePayAttempt($normalized);
        $payOrder = $attempt['pay_order'];

        return [
            'merchant' => $merchant,
            'pay_order' => $payOrder,
            'payment_result' => $attempt['payment_result'] ?? [],
            'pay_params' => $attempt['pay_params'] ?? [],
            'payment_page_url' => $this->buildPaymentPageUrl((string) $payOrder->pay_no),
        ];
    }

    /**
     * 预创建收银台业务单。
     *
     * @param array $payload 请求参数
     * @param Request $request 请求对象
     * @return array<string, mixed>
     */
    private function prepareCashierSubmit(array $payload, Request $request): array
    {
        $merchant = $this->authorizeMerchant($payload, true);
        $merchantOrderNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $subject = trim((string) ($payload['name'] ?? ''));
        $amount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
        if ($merchantOrderNo === '') {
            throw new ValidationException('out_trade_no 不能为空');
        }
        if ($subject === '') {
            throw new ValidationException('name 不能为空');
        }
        if ($amount <= 0) {
            throw new ValidationException('money 参数不合法');
        }

        // 收银台首屏只需要业务单上下文，不在这里创建支付单。
        $orderFields = $this->orderInputAssembler->buildOrderFields($payload, $request, null, [
            '_protocol_version' => 'v2',
        ]);
        $normalized = [
            'merchant_id' => (int) $merchant->id,
            'merchant_order_no' => $merchantOrderNo,
            'pay_amount' => $amount,
            'subject' => (string) $orderFields['subject'],
            'body' => (string) $orderFields['body'],
            'notify_url' => (string) $orderFields['notify_url'],
            'return_url' => (string) $orderFields['return_url'],
            'client_ip' => (string) $orderFields['client_ip'],
            'device' => (string) $orderFields['device'],
            'ext_json' => (array) $orderFields['ext_json'],
        ];

        $result = $this->payOrderService->prepareCashierBizOrder($normalized);

        return [
            'merchant' => $merchant,
            'biz_order' => $result['biz_order'] ?? null,
            'cashier_url' => (string) ($result['cashier_url'] ?? ''),
        ];
    }

    /**
     * 构建创建支付响应。
     *
     * @param array<string, mixed> $attempt 支付尝试结果
     * @return array<string, mixed>
     */
    private function buildCreateResponse(array $attempt): array
    {
        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $paymentResult = (array) ($attempt['payment_result'] ?? []);

        return [
            'code' => $this->successCode(),
            'msg' => 'success',
            'trade_no' => (string) $payOrder->pay_no,
            'pay_type' => strtolower(trim((string) ($payParams['type'] ?? $paymentResult['pay_type'] ?? 'qrcode'))),
            'pay_info' => $payParams,
        ];
    }

    /**
     * 解析支付上下文。
     *
     * @param int $merchantId 商户ID
     * @param array $payload 请求参数
     * @return array{pay_order: PayOrder, biz_order: BizOrder|null}|null
     */
    private function resolvePayOrderContext(int $merchantId, array $payload): ?array
    {
        $payNo = trim((string) ($payload['trade_no'] ?? ''));
        $merchantOrderNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $payOrder = null;
        $bizOrder = null;

        if ($payNo !== '') {
            $payOrder = $this->payOrderRepository->findByPayNo($payNo);
            if ($payOrder) {
                $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
            }
        }

        if (!$payOrder && $merchantOrderNo !== '') {
            $bizOrder = $this->bizOrderRepository->findByMerchantAndOrderNo($merchantId, $merchantOrderNo);
            if ($bizOrder) {
                $payOrder = $this->payOrderRepository->findLatestByBizNo((string) $bizOrder->biz_no);
            }
        }

        if (!$payOrder || (int) $payOrder->merchant_id !== $merchantId) {
            return null;
        }

        if (!$bizOrder) {
            $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        }

        return [
            'pay_order' => $payOrder,
            'biz_order' => $bizOrder,
        ];
    }

    /**
     * 解析退款单。
     *
     * @param int $merchantId 商户ID
     * @param array $payload 请求参数
     * @return RefundOrder
     */
    private function resolveRefundOrder(int $merchantId, array $payload): RefundOrder
    {
        $refundNo = trim((string) ($payload['refund_no'] ?? ''));
        $outRefundNo = trim((string) ($payload['out_refund_no'] ?? ''));

        if ($refundNo !== '') {
            $refundOrder = $this->refundOrderRepository->findByRefundNo($refundNo);
            if (!$refundOrder || (int) $refundOrder->merchant_id !== $merchantId) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }

            return $refundOrder;
        }

        if ($outRefundNo !== '') {
            $refundOrder = $this->refundOrderRepository->findByMerchantRefundNo($merchantId, $outRefundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['out_refund_no' => $outRefundNo]);
            }

            return $refundOrder;
        }

        throw new ValidationException('refund_no/out_refund_no 不能为空');
    }

    /**
     * 构建订单响应。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     */
    private function buildOrderResponse(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $bizExtJson = (array) (($bizOrder?->ext_json) ?? []);
        $merchantExt = (array) ($bizExtJson['merchant'] ?? []);
        $refundAmount = (int) ($bizOrder?->refund_amount ?? 0);

        return [
            'code' => $this->successCode(),
            'msg' => 'success',
            'trade_no' => (string) $payOrder->pay_no,
            'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? ''),
            'api_trade_no' => (string) ($payOrder->channel_trade_no ?: $payOrder->channel_order_no ?: ''),
            'type' => $this->resolvePaymentTypeCode((int) $payOrder->pay_type_id),
            'status' => $this->resolveEpayOrderStatus($payOrder, $refundAmount),
            'pid' => (int) $payOrder->merchant_id,
            'addtime' => FormatHelper::dateTime($payOrder->created_at),
            'endtime' => FormatHelper::dateTime($payOrder->paid_at),
            'name' => (string) ($bizOrder?->subject ?? ''),
            'money' => FormatHelper::amount((int) $payOrder->pay_amount),
            'refundmoney' => FormatHelper::amount($refundAmount),
            'param' => $this->stringifyValue($merchantExt['param'] ?? ''),
            'buyer' => $this->stringifyValue($merchantExt['buyer'] ?? ''),
            'clientip' => $this->stringifyValue($payOrder->client_ip ?? ''),
        ];
    }

    /**
     * 构建退款响应。
     *
     * @param RefundOrder $refundOrder 退款单
     * @param PayOrder|null $payOrder 支付单
     * @param BizOrder|null $bizOrder 业务单
     * @return array<string, mixed>
     */
    private function buildRefundResponse(RefundOrder $refundOrder, ?PayOrder $payOrder = null, ?BizOrder $bizOrder = null): array
    {
        $payOrder ??= $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $refundOrder->biz_no);

        return [
            'code' => $this->successCode(),
            'msg' => 'success',
            'refund_no' => (string) $refundOrder->refund_no,
            'out_refund_no' => (string) $refundOrder->merchant_refund_no,
            'trade_no' => (string) $refundOrder->pay_no,
            'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? ''),
            'money' => FormatHelper::amount((int) $refundOrder->refund_amount),
            'reducemoney' => FormatHelper::amount((int) ($bizOrder?->refund_amount ?? 0)),
            'status' => (int) $refundOrder->status === TradeConstant::REFUND_STATUS_SUCCESS ? 1 : 0,
            'addtime' => FormatHelper::dateTime($refundOrder->created_at),
        ];
    }

    /**
     * 解析支付方式。
     *
     * @param string $typeCode 支付方式编码
     * @return \app\model\payment\PaymentType
     */
    private function resolvePaymentType(string $typeCode)
    {
        $typeCode = trim($typeCode);
        $paymentType = $this->paymentTypeService->findByCode($typeCode);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('支付方式不支持');
        }

        return $paymentType;
    }

    /**
     * 根据支付方式 ID 解析支付方式编码。
     *
     * @param int $payTypeId 支付方式ID
     * @return string
     */
    private function resolvePaymentTypeCode(int $payTypeId): string
    {
        return $this->paymentTypeService->resolveCodeById($payTypeId);
    }

    /**
     * 计算 ePay 查询状态。
     *
     * @param PayOrder $payOrder 支付单
     * @param int $refundAmount 已退款金额
     * @return int
     */
    private function resolveEpayOrderStatus(PayOrder $payOrder, int $refundAmount): int
    {
        if ((int) $payOrder->status === TradeConstant::ORDER_STATUS_SUCCESS) {
            return $refundAmount > 0 ? 2 : 1;
        }

        return 0;
    }

    /**
     * 认证商户并校验请求签名。
     *
     * @param array $payload 请求参数
     * @param bool $verifySignature 是否验签
     * @return Merchant
     */
    private function authorizeMerchant(array $payload, bool $verifySignature): Merchant
    {
        $merchantId = (int) ($payload['pid'] ?? 0);
        if ($merchantId <= 0) {
            throw new ValidationException('pid 不能为空');
        }

        $merchant = $this->merchantService->ensureMerchantEnabled($merchantId);
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::CREDENTIAL_STATUS_ENABLED) {
            throw new ValidationException('商户 API 凭证未开通');
        }

        $publicKey = trim((string) ($credential->merchant_public_key ?? ''));
        if ($publicKey === '') {
            throw new ValidationException('商户 RSA 公钥未配置');
        }

        if ($verifySignature) {
            $timestamp = (int) ($payload['timestamp'] ?? 0);
            if ($timestamp <= 0 || abs(time() - $timestamp) > (int) config('epay.v2.timestamp_ttl', 300)) {
                throw new ValidationException('timestamp 校验失败');
            }

            $sign = trim((string) ($payload['sign'] ?? ''));
            if ($sign === '') {
                throw new ValidationException('sign 不能为空');
            }

            $signType = $this->signerManager->normalizeSignType((string) ($payload['sign_type'] ?? AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA));
            $verifyPayload = $payload;
            unset($verifyPayload['sign'], $verifyPayload['sign_type']);

            if (!$this->signerManager->verify($verifyPayload, $signType, $sign, $publicKey)) {
                throw new ValidationException('签名验证失败');
            }
        }

        return $merchant;
    }

    /**
     * 响应签名。
     *
     * @param array<string, mixed> $data 响应数据
     * @return array<string, mixed>
     */
    private function signResponse(array $data): array
    {
        $data['timestamp'] = (string) ($data['timestamp'] ?? time());
        $data['sign_type'] = $this->resolveResponseSignType();
        $privateKey = trim((string) config('epay.v2.platform_private_key', ''));
        if ($privateKey === '') {
            throw new ValidationException('平台 RSA 私钥未配置');
        }

        $signParams = $data;
        unset($signParams['sign'], $signParams['sign_type']);
        $data['sign'] = $this->signerManager->sign($signParams, $data['sign_type'], $privateKey);

        return $data;
    }

    /**
     * 解析响应签名类型。
     *
     * 响应始终回写文档约定的规范值，避免把内部别名暴露给商户。
     *
     * @return string
     */
    private function resolveResponseSignType(): string
    {
        $signType = $this->signerManager->normalizeSignType((string) config('epay.v2.sign_type', AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA));

        return match ($signType) {
            AuthConstant::API_SIGN_NORMALIZED_SHA256_WITH_RSA => AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            AuthConstant::API_SIGN_NAME_MD5 => AuthConstant::API_SIGN_NAME_MD5,
            default => AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
        };
    }

    /**
     * 规范化错误信息。
     *
     * @param Throwable $e 异常
     * @return string
     */
    private function normalizeErrorMessage(Throwable $e): string
    {
        return $e->getMessage() ?: '请求失败';
    }

    /**
     * V2 协议成功码。
     *
     * @return int
     */
    private function successCode(): int
    {
        return 0;
    }

    /**
     * 解析 V2 失败码。
     *
     * 文档只约定 `0` 为成功，其它值为失败；这里优先保留异常业务码，缺失时回退到 `1`。
     *
     * @param Throwable|null $e 异常对象
     * @return int
     */
    private function resolveFailureCode(?Throwable $e = null): int
    {
        $code = (int) ($e?->getCode() ?? 0);

        return $code === $this->successCode() ? 1 : $code;
    }

    /**
     * 金额字符串转分。
     *
     * @param string $money 金额字符串
     * @return int
     */
    private function parseMoneyToAmount(string $money): int
    {
        $money = trim($money);
        if ($money === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return 0;
        }

        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return ((int) $integer) * 100 + (int) substr($fraction, 0, 2);
    }

    /**
     * 解析数字值。
     *
     * @param mixed $value 值
     * @return string
     */
    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '';
        }

        return trim((string) $value);
    }

    /**
     * 获取当前日期。
     *
     * @return string
     */
    private function nowDate(): string
    {
        return FormatHelper::timestamp(time(), 'Y-m-d');
    }

    /**
     * 获取昨日日期。
     *
     * @return string
     */
    private function yesterdayDate(): string
    {
        return FormatHelper::timestamp(strtotime('-1 day'), 'Y-m-d');
    }

    /**
     * 解析插件退款渠道单号。
     *
     * @param array $pluginResult 插件结果
     * @return string
     */
    private function resolveRefundChannelNo(array $pluginResult): string
    {
        foreach (['chan_refund_no', 'refund_no', 'trade_no', 'out_request_no'] as $key) {
            $value = $this->stringifyValue($pluginResult[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * 判断插件是否成功。
     *
     * @param array $pluginResult 插件结果
     * @return bool
     */
    private function isPluginSuccess(array $pluginResult): bool
    {
        return !array_key_exists('success', $pluginResult) || (bool) $pluginResult['success'];
    }

    /**
     * 按支付载体生成浏览器响应。
     *
     * 页面跳转支付允许直接返回渠道跳转页或 HTML，其余情况回到平台支付页承载。
     *
     * @param array<string, mixed> $attempt 支付尝试结果
     * @return Response
     */
    private function buildBrowserSubmitResponse(array $attempt): Response
    {
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $paymentResult = (array) ($attempt['payment_result'] ?? []);
        $payType = strtolower(trim((string) ($payParams['type'] ?? $paymentResult['pay_type'] ?? '')));
        $paymentPageUrl = (string) ($attempt['payment_page_url'] ?? '');

        if (in_array($payType, ['jump', 'url', 'web', 'h5'], true)) {
            $jumpUrl = $this->resolveBrowserPayUrl($payParams);
            if ($jumpUrl !== '') {
                return redirect($jumpUrl);
            }
        }

        if (in_array($payType, ['html', 'form'], true)) {
            $html = $this->resolveBrowserHtml($payParams);
            if ($html !== '') {
                return response($html, 200, [
                    'Content-Type' => 'text/html; charset=utf-8',
                ]);
            }
        }

        if ($paymentPageUrl === '') {
            throw new ValidationException('支付页跳转地址生成失败');
        }

        return redirect($paymentPageUrl);
    }

    /**
     * 构建支付页地址。
     *
     * @param string $payNo 支付单号
     * @return string
     */
    private function buildPaymentPageUrl(string $payNo): string
    {
        return rtrim((string) sys_config('site_url'), '/') . '/payment/' . rawurlencode($payNo);
    }

    /**
     * 提取浏览器跳转地址。
     *
     * @param array<string, mixed> $payParams 支付参数
     * @return string
     */
    private function resolveBrowserPayUrl(array $payParams): string
    {
        foreach (['payurl', 'pay_url', 'url', 'redirect_url', 'mweb_url'] as $key) {
            $value = $this->stringifyValue($payParams[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * 提取浏览器可直接渲染的 HTML。
     *
     * @param array<string, mixed> $payParams 支付参数
     * @return string
     */
    private function resolveBrowserHtml(array $payParams): string
    {
        foreach (['html', 'html_form', 'form_html'] as $key) {
            $value = $payParams[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }
}
