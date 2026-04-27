<?php

namespace app\service\payment\epay;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
use app\exception\ValidationException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentType;
use app\repository\account\balance\MerchantAccountRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\merchant\MerchantService;
use app\service\merchant\security\MerchantApiCredentialService;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\order\PayOrderService;
use app\service\payment\order\PaymentOrderInputAssembler;
use app\service\payment\order\RefundService;
use app\service\payment\runtime\PaymentPluginManager;
use support\Request;
use support\Response;
use Throwable;

/**
 * ePay V1 协议服务。
 *
 * 负责将旧协议请求转换为当前支付、退款和查询流程。
 *
 * @property MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
 * @property PaymentTypeService $paymentTypeService 支付类型服务
 * @property PayOrderService $payOrderService 支付订单服务
 * @property PaymentOrderInputAssembler $orderInputAssembler 支付入参组装器
 * @property PaymentPluginManager $paymentPluginManager 支付插件管理器
 * @property MerchantAccountRepository $merchantAccountRepository 商户账户仓库
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property SettlementOrderRepository $settlementOrderRepository 结算订单仓库
 * @property RefundService $refundService 退款服务
 */
class EpayV1ProtocolService extends BaseService
{
    private const API_ACTIONS = ['query', 'settle', 'order', 'orders', 'refund'];

    /**
     * 构造方法。
     *
     * @param MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
     * @param PaymentTypeService $paymentTypeService 支付类型服务
     * @param PayOrderService $payOrderService 支付订单服务
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param MerchantAccountRepository $merchantAccountRepository 商户账户仓库
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param SettlementOrderRepository $settlementOrderRepository 结算订单仓库
     * @param RefundService $refundService 退款服务
     * @return void
     */
    public function __construct(
        protected MerchantApiCredentialService $merchantApiCredentialService,
        protected MerchantService $merchantService,
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderService $payOrderService,
        protected PaymentOrderInputAssembler $orderInputAssembler,
        protected PaymentPluginManager $paymentPluginManager,
        protected EpaySignerManager $epaySignerManager,
        protected MerchantAccountRepository $merchantAccountRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository,
        protected RefundService $refundService
    ) {
    }

    /**
     * 处理页面跳转支付入口。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return Response 跳转响应或错误 JSON
     * @throws ValidationException
     */
    public function submit(array $payload, Request $request): Response
    {
        try {
            $typeCode = trim((string) ($payload['type'] ?? ''));
            if ($typeCode === '') {
                // `type` 为空时先创建收银台业务单，选完方式后再进入正式支付单流程。
                $attempt = $this->prepareCashierSubmit($payload, $request);
                $targetUrl = (string) ($attempt['cashier_url'] ?? '');
                if ($targetUrl === '') {
                    throw new ValidationException('收银台跳转地址生成失败');
                }

                return redirect($targetUrl);
            }

            return $this->buildBrowserSubmitResponse($this->prepareSubmitAttempt($payload, $request));
        } catch (Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $this->normalizeErrorMessage($e),
            ]);
        }
    }

    /**
     * 处理 API 支付入口。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return array<string, mixed> ePay 风格响应
     */
    public function mapi(array $payload, Request $request): array
    {
        try {
            $attempt = $this->prepareSubmitAttempt($payload, $request);
            return $this->buildMapiResponse($attempt);
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e)];
        }
    }

    /**
     * 处理旧版兼容入口。
     *
     * 支持 `query`、`settle`、`order`、`orders` 和 `refund` 五种操作。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    public function api(array $payload): array
    {
        $act = strtolower(trim((string) ($payload['act'] ?? '')));
        if (!in_array($act, self::API_ACTIONS, true)) {
            return ['code' => 0, 'msg' => '不支持的操作类型'];
        }

        return match ($act) {
            'query' => $this->queryMerchantInfo($payload),
            'settle' => $this->querySettlementList($payload),
            'order' => $this->queryOrder($payload),
            'orders' => $this->queryOrders($payload),
            'refund' => $this->createRefund($payload),
        };
    }

    /**
     * 查询商户信息，对应 `act=query`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    public function queryMerchantInfo(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $auth = $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $merchant = $auth['merchant'];
            $credential = $auth['credential'];
            $account = $this->merchantAccountRepository->findByMerchantId($merchantId);
            // 旧协议会同时返回总单量、今日单量和昨日单量，便于上游直接做商户概览。
            $todayDate = FormatHelper::timestamp(time(), 'Y-m-d');
            $lastDayDate = FormatHelper::timestamp(strtotime('-1 day'), 'Y-m-d');
            $totalOrders = (int) $this->payOrderRepository->query()->where('merchant_id', $merchantId)->count();
            $todayOrders = (int) $this->payOrderRepository->query()->where('merchant_id', $merchantId)->whereDate('created_at', $todayDate)->count();
            $lastDayOrders = (int) $this->payOrderRepository->query()->where('merchant_id', $merchantId)->whereDate('created_at', $lastDayDate)->count();

            return [
                'code' => 1,
                'pid' => (int) $merchant->id,
                'key' => (string) $credential->api_key,
                'active' => (int) $merchant->status,
                'money' => FormatHelper::amount((int) ($account->available_balance ?? 0)),
                'type' => (int) ($merchant->settle_type ?? 4),
                'account' => (string) $merchant->settlement_account_no,
                'username' => (string) $merchant->settlement_account_name,
                'orders' => $totalOrders,
                'order_today' => $todayOrders,
                'order_lastday' => $lastDayOrders,
            ];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e)];
        }
    }

    /**
     * 查询结算记录列表，对应 `act=settle`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    public function querySettlementList(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $rows = $this->settlementOrderRepository->query()->where('merchant_id', $merchantId)->orderByDesc('id')->get();

            // 旧协议列表只需要基础字段和金额文本，这里直接整理成可展示数组。
            return [
                'code' => 1,
                'msg' => '查询结算记录成功！',
                'data' => $rows->map(function ($row): array {
                    return [
                        'settle_no' => (string) $row->settle_no,
                        'cycle_type' => (int) $row->cycle_type,
                        'cycle_key' => (string) $row->cycle_key,
                        'status' => (int) $row->status,
                        'gross_amount' => FormatHelper::amount((int) $row->gross_amount),
                        'net_amount' => FormatHelper::amount((int) $row->net_amount),
                        'accounted_amount' => FormatHelper::amount((int) $row->accounted_amount),
                        'created_at' => FormatHelper::dateTime($row->created_at ?? null),
                        'completed_at' => FormatHelper::dateTime($row->completed_at ?? null),
                    ];
                })->all(),
            ];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e)];
        }
    }

    /**
     * 查询单个订单，对应 `act=order`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    public function queryOrder(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $context = $this->resolvePayOrderContext($merchantId, $payload);
            if (!$context) {
                return ['code' => 0, 'msg' => '订单不存在'];
            }

            // 旧协议查询单号时，要把支付单和业务单合并成同一份响应结构。
            return ['code' => 1, 'msg' => '查询订单号成功！'] + $this->formatEpayOrderRow($context['pay_order'], $context['biz_order']);
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e)];
        }
    }

    /**
     * 批量查询订单，对应 `act=orders`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    public function queryOrders(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            // 旧接口默认只允许一次拉少量订单，这里沿用上限 50 的兼容口径。
            $limit = min(50, max(1, (int) ($payload['limit'] ?? 20)));
            $page = max(1, (int) ($payload['page'] ?? 1));
            $paginator = $this->payOrderRepository->query()->where('merchant_id', $merchantId)->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);
            $items = $paginator->items();
            $bizOrderMap = [];
            $bizNos = array_values(array_unique(array_filter(array_map(function ($row): string {
                return trim((string) ($row->biz_no ?? ''));
            }, $items))));

            if ($bizNos !== []) {
                foreach ($this->bizOrderRepository->query()->whereIn('biz_no', $bizNos)->get() as $bizOrder) {
                    $bizOrderMap[(string) $bizOrder->biz_no] = $bizOrder;
                }
            }

            return [
                'code' => 1,
                'msg' => '查询结算记录成功！',
                // 批量查询和单条查询共用同一套格式化器，避免字段口径不一致。
                'data' => array_map(function ($row) use ($bizOrderMap): array {
                    $bizNo = (string) ($row->biz_no ?? '');

                    return $this->formatEpayOrderRow($row, $bizOrderMap[$bizNo] ?? null);
                }, $items),
            ];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e)];
        }
    }

    /**
     * 提交退款申请，对应 `act=refund`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    public function createRefund(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            // 先确认退款目标单据归属当前商户，避免旧协议拿着别人的单号误发退款。
            $context = $this->resolvePayOrderContext($merchantId, $payload);
            if (!$context) {
                return ['code' => 0, 'msg' => '订单不存在'];
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];
            $refundAmount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
            if ($refundAmount <= 0) {
                return ['code' => 0, 'msg' => '退款金额不合法'];
            }

            $refundOrder = $this->refundService->createRefund([
                'pay_no' => (string) $payOrder->pay_no,
                'merchant_refund_no' => trim((string) ($payload['refund_no'] ?? $payload['merchant_refund_no'] ?? '')),
                'refund_amount' => $refundAmount,
                'reason' => trim((string) ($payload['reason'] ?? '')),
            ]);

            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            // 不同插件返回的退款结果字段不完全一致，这里仍按旧协议的退款参数重新组织一次。
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
                // 渠道明确失败时，先把退款单推进失败态，再把旧协议响应收口成失败文案。
                $this->refundService->markRefundFailed((string) $refundOrder->refund_no, [
                    'failed_at' => $this->now(),
                    'last_error' => (string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '退款失败'),
                    'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
                ]);

                return ['code' => 0, 'msg' => (string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '退款失败')];
            }

            $this->refundService->markRefundSuccess((string) $refundOrder->refund_no, [
                'succeeded_at' => $this->now(),
                'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
            ]);

            return ['code' => 1, 'msg' => '退款成功'];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e)];
        }
    }

    /**
     * 预处理支付提交请求。
     *
     * 这里负责把旧协议载荷转换为当前支付单创建所需的数据结构。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return array<string, mixed> 预处理数据
     */
    private function prepareSubmitAttempt(array $payload, Request $request): array
    {
        // 先把旧协议载荷转换成当前系统的统一入参，再交给支付单主流程处理。
        $normalized = $this->normalizeSubmitPayload($payload, $request, false);
        $result = $this->payOrderService->preparePayAttempt($normalized);
        $payOrder = $result['pay_order'];
        $payParams = (array) ($result['pay_params'] ?? []);

        return [
            'normalized_payload' => $normalized,
            'result' => $result,
            'pay_order' => $payOrder,
            'pay_params' => $payParams,
            'payment_page_url' => $this->buildPaymentPageUrl((string) $payOrder->pay_no),
        ];
    }

    /**
     * 预创建收银台业务单。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return array<string, mixed> 预处理数据
     */
    private function prepareCashierSubmit(array $payload, Request $request): array
    {
        $normalized = $this->normalizeSubmitPayload($payload, $request, true);
        $result = $this->payOrderService->prepareCashierBizOrder($normalized);

        return [
            'normalized_payload' => $normalized,
            'result' => $result,
            'merchant' => $result['merchant'] ?? null,
            'biz_order' => $result['biz_order'] ?? null,
            'cashier_url' => (string) ($result['cashier_url'] ?? ''),
        ];
    }

    /**
     * 归一化提交支付参数。
     *
     * 这里会完成签名校验、金额转分、支付方式解析，并把旧协议字段写入扩展信息。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return array<string, mixed> 当前支付单创建参数
     * @throws ValidationException
     */
    private function normalizeSubmitPayload(array $payload, Request $request, bool $allowEmptyType = false): array
    {
        $merchantId = (int) ($payload['pid'] ?? 0);
        if ($merchantId <= 0) {
            throw new ValidationException('pid 参数不能为空');
        }

        $sign = trim((string) ($payload['sign'] ?? ''));
        if ($sign === '') {
            throw new ValidationException('sign 参数不能为空');
        }

        $credential = $this->merchantApiCredentialService->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::CREDENTIAL_STATUS_ENABLED) {
            throw new ValidationException('商户 API 凭证未开通');
        }

        $signType = strtoupper((string) ($payload['sign_type'] ?? AuthConstant::API_SIGN_NAME_MD5));
        if ($signType !== AuthConstant::API_SIGN_NAME_MD5) {
            throw new ValidationException('仅支持 MD5 签名');
        }

        if (!$this->epaySignerManager->verify(
            $this->buildV1SignParams($payload),
            $signType,
            $sign,
            (string) $credential->api_key
        )) {
            throw new ValidationException('签名验证失败');
        }

        $this->merchantService->ensureMerchantEnabled($merchantId);
        $typeCode = trim((string) ($payload['type'] ?? ''));
        $merchantOrderNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $subject = trim((string) ($payload['name'] ?? ''));
        $amount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
        $paymentType = null;

        if ($typeCode === '') {
            if (!$allowEmptyType) {
                throw new ValidationException('type 参数不能为空');
            }
        } else {
            $paymentType = $this->resolveSubmitPaymentType($typeCode);
        }

        if ($merchantOrderNo === '') {
            throw new ValidationException('out_trade_no 参数不能为空');
        }
        if ($subject === '') {
            throw new ValidationException('name 参数不能为空');
        }
        if ($amount <= 0) {
            throw new ValidationException('money 参数不合法');
        }

        // 旧协议的展示字段统一交给 assembler，避免 submit / mapi / cashier 三处口径漂移。
        $orderFields = $this->orderInputAssembler->buildOrderFields($payload, $request, null, [
            '_protocol_version' => 'v1',
        ]);

        $normalized = [
            'merchant_id' => (int) ($payload['pid'] ?? 0),
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

        if ($paymentType) {
            $normalized['pay_type_id'] = (int) $paymentType->id;
        }

        return $normalized;
    }

    /**
     * 过滤旧协议签名参数。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> 签名参数
     */
    private function buildV1SignParams(array $payload): array
    {
        $params = $payload;
        unset($params['sign'], $params['sign_type'], $params['key']);

        foreach ($params as $paramKey => $paramValue) {
            if ($paramValue === '' || $paramValue === null) {
                unset($params[$paramKey]);
            }
        }

        return $params;
    }

    /**
     * 解析提交支付方式。
     *
     * 只接受显式传入且启用中的支付方式。
     *
     * @param string $typeCode 支付方式编码
     * @return PaymentType 支付方式模型
     * @throws ValidationException
     */
    private function resolveSubmitPaymentType(string $typeCode): PaymentType
    {
        $typeCode = trim($typeCode);
        $paymentType = $this->paymentTypeService->findByCode($typeCode);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('支付方式不支持');
        }

        return $paymentType;
    }

    /**
     * 构建旧版 MAPI 返回结构。
     *
     * 根据当前支付尝试结果，输出 payurl、qrcode 或 urlscheme 等旧协议字段。
     *
     * @param array $attempt 支付尝试结果
     * @return array<string, mixed> ePay 风格响应
     */
    private function buildMapiResponse(array $attempt): array
    {
        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $normalizedPayload = (array) ($attempt['normalized_payload'] ?? []);
        $paymentPageUrl = (string) ($attempt['payment_page_url'] ?? $this->buildPaymentPageUrl((string) $payOrder->pay_no));
        $payNo = (string) $payOrder->pay_no;
        $response = ['code' => 1, 'msg' => '提交成功', 'trade_no' => $payNo];
        $device = strtolower(trim((string) ($normalizedPayload['device'] ?? '')));
        $type = strtolower(trim((string) ($payParams['type'] ?? '')));
        $resolved = $this->resolveV1PayResponse($payParams, $device, $paymentPageUrl, $type);

        if ($resolved['field'] !== '') {
            $response[$resolved['field']] = $resolved['value'];
        }

        return $response;
    }

    /**
     * 解析旧版 MAPI 的单字段返回体。
     *
     * 旧协议同一时刻只会返回 `payurl`、`qrcode`、`urlscheme` 中的一个。
     *
     * @param array<string, mixed> $payParams 插件返回参数
     * @param string $device 请求设备
     * @param string $paymentPageUrl 支付页地址
     * @param string $type 插件返回类型
     * @return array{field: string, value: string}
     */
    private function resolveV1PayResponse(array $payParams, string $device, string $paymentPageUrl, string $type): array
    {
        if ($device === 'jump') {
            return ['field' => 'payurl', 'value' => $paymentPageUrl];
        }

        if ($type === 'qrcode') {
            $qrcode = $this->stringifyValue($payParams['qrcode_url'] ?? '');
            if ($qrcode !== '') {
                return ['field' => 'qrcode', 'value' => $qrcode];
            }
        }

        if ($type === 'jsapi') {
            $urlscheme = $this->stringifyValue($payParams['order_string'] ?? '');
            if ($urlscheme !== '') {
                return ['field' => 'urlscheme', 'value' => $urlscheme];
            }
        }

        return ['field' => 'payurl', 'value' => $paymentPageUrl];
    }

    /**
     * 将当前支付单格式化为旧版订单查询结构。
     *
     * @param PayOrder $payOrder 支付订单
     * @param BizOrder|null $bizOrder 业务订单
     * @return array<string, mixed> 旧版订单结构
     */
    private function formatEpayOrderRow(PayOrder $payOrder, ?BizOrder $bizOrder = null): array
    {
        $bizOrder ??= $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        $extJson = (array) (($bizOrder?->ext_json) ?? []);
        $merchantExt = (array) ($extJson['merchant'] ?? []);

        return [
            'trade_no' => (string) $payOrder->pay_no,
            'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? $extJson['merchant_order_no'] ?? ''),
            'api_trade_no' => (string) ($payOrder->channel_trade_no ?: $payOrder->channel_order_no ?: ''),
            'type' => $this->resolvePaymentTypeCode((int) $payOrder->pay_type_id),
            'pid' => (int) $payOrder->merchant_id,
            'addtime' => FormatHelper::dateTime($payOrder->created_at),
            'endtime' => FormatHelper::dateTime($payOrder->paid_at),
            'name' => (string) ($bizOrder?->subject ?? $extJson['subject'] ?? ''),
            'money' => FormatHelper::amount((int) $payOrder->pay_amount),
            'status' => (int) $payOrder->status === TradeConstant::ORDER_STATUS_SUCCESS ? 1 : 0,
            'param' => $this->stringifyValue($merchantExt['param'] ?? ''),
            'buyer' => $this->stringifyValue($merchantExt['buyer'] ?? ''),
        ];
    }

    /**
     * 解析支付订单上下文。
     *
     * 优先按 `trade_no` 查找，其次按 `out_trade_no` 回退，并校验订单归属当前商户。
     *
     * @param int $merchantId 商户ID
     * @param array $payload 请求载荷
     * @return array{pay_order: PayOrder, biz_order: BizOrder|null}|null 上下文
     */
    private function resolvePayOrderContext(int $merchantId, array $payload): ?array
    {
        $payNo = trim((string) ($payload['trade_no'] ?? ''));
        $merchantOrderNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $payOrder = null;
        $bizOrder = null;

        if ($payNo !== '') {
            // 旧协议如果传了 trade_no，就优先按支付单号定位，命中率最高。
            $payOrder = $this->payOrderRepository->findByPayNo($payNo);
            if ($payOrder) {
                $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
            }
        }

        if (!$payOrder && $merchantOrderNo !== '') {
            // 没有 trade_no 时，再按商户单号反查业务单和最新支付单。
            $bizOrder = $this->bizOrderRepository->findByMerchantAndOrderNo($merchantId, $merchantOrderNo);
            if ($bizOrder) {
                // 旧协议经常只传商户单号，这里拿业务单找到最新一笔支付单。
                $payOrder = $this->payOrderRepository->findLatestByBizNo((string) $bizOrder->biz_no);
            }
        }

        // 旧协议有时会传到别家商户的单号，这里必须再次校验归属，避免跨商户读取。
        if (!$payOrder || (int) $payOrder->merchant_id !== $merchantId) {
            return null;
        }

        if (!$bizOrder) {
            $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
        }

        return ['pay_order' => $payOrder, 'biz_order' => $bizOrder];
    }

    /**
     * 根据支付方式 ID 解析支付方式编码。
     *
     * @param int $payTypeId 支付方式ID
     * @return string 支付方式编码
     */
    private function resolvePaymentTypeCode(int $payTypeId): string
    {
        return $this->paymentTypeService->resolveCodeById($payTypeId);
    }

    /**
     * 将元金额转成分。
     *
     * @param string $money 金额字符串
     * @return int 金额分值，非法时返回 0
     */
    private function parseMoneyToAmount(string $money): int
    {
        $money = trim($money);
        if ($money === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return 0;
        }

        [$integer, $fraction] = array_pad(explode('.', $money, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        // 旧协议金额按“元”传入，内部统一转成“分”处理，避免 float 精度漂移。
        return ((int) $integer) * 100 + (int) substr($fraction, 0, 2);
    }

    /**
     * 规范化异常提示。
     *
     * @param Throwable $e 异常对象
     * @return string 错误提示
     */
    private function normalizeErrorMessage(Throwable $e): string
    {
        return $e->getMessage() !== '' ? $e->getMessage() : '请求失败';
    }

    /**
     * 构建支付页地址。
     *
     * @param string $payNo 支付单号
     * @return string 支付页 URL
     */
    private function buildPaymentPageUrl(string $payNo): string
    {
        return rtrim((string) sys_config('site_url'), '/') . '/payment/' . rawurlencode($payNo);
    }

    /**
     * 按支付载体生成浏览器响应。
     *
     * 页面跳转支付允许直接重定向或直接输出 HTML，其余载体统一回到平台支付页。
     *
     * @param array<string, mixed> $attempt 支付尝试结果
     * @return Response
     */
    private function buildBrowserSubmitResponse(array $attempt): Response
    {
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $payType = strtolower(trim((string) ($payParams['type'] ?? '')));
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

    /**
     * 判断插件返回的 success 标记。
     *
     * 如果插件未显式返回 `success`，则默认视为成功。
     *
     * @param array $pluginResult 插件结果
     * @return bool 插件是否通过
     */
    private function isPluginSuccess(array $pluginResult): bool
    {
        return !array_key_exists('success', $pluginResult) || (bool) $pluginResult['success'];
    }

    /**
     * 解析退款渠道单号。
     *
     * @param array $pluginResult 插件结果
     * @param string $default 默认值
     * @return string 渠道退款单号
     */
    private function resolveRefundChannelNo(array $pluginResult, string $default = ''): string
    {
        foreach (['chan_refund_no', 'refund_no', 'trade_no', 'out_request_no'] as $key) {
            if (array_key_exists($key, $pluginResult)) {
                $value = $this->stringifyValue($pluginResult[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return $default;
    }

    /**
     * 将任意值规范化为字符串。
     *
     * @param array|object|bool|float|int|string|null $value 待转换值
     * @return string 规范化后的字符串
     */
    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if (is_array($value) || is_object($value)) {
            // 复杂结构直接 JSON 化，保证旧协议回显时仍然可读。
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '';
        }
        return (string) $value;
    }

}
