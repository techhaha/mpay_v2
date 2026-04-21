<?php

namespace app\service\payment\compat;

use app\common\base\BaseService;
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
use app\service\merchant\security\MerchantApiCredentialService;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\order\PayOrderService;
use app\service\payment\order\RefundService;
use app\service\payment\runtime\PaymentPluginManager;
use support\Request;
use support\Response;
use Throwable;

/**
 * 旧版 Epay 协议兼容服务。
 *
 * 负责将旧协议请求转换为当前支付、退款和查询流程。
 *
 * @property MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
 * @property PaymentTypeService $paymentTypeService 支付类型服务
 * @property PayOrderService $payOrderService 支付订单服务
 * @property PaymentPluginManager $paymentPluginManager 支付插件管理器
 * @property MerchantAccountRepository $merchantAccountRepository 商户账户仓库
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property SettlementOrderRepository $settlementOrderRepository 结算订单仓库
 * @property RefundService $refundService 退款服务
 */
class EpayCompatService extends BaseService
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
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderService $payOrderService,
        protected PaymentPluginManager $paymentPluginManager,
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
            $attempt = $this->prepareSubmitAttempt($payload, $request);
            $targetUrl = (string) ($attempt['cashier_url'] ?? '');

            if ($targetUrl === '') {
                throw new ValidationException('收银台跳转地址生成失败');
            }

            return redirect($targetUrl);
        } catch (Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $this->normalizeErrorMessage($e, '提交失败'),
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
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '提交失败')];
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
                'type' => $this->resolveMerchantSettlementType($merchant),
                'account' => (string) $merchant->settlement_account_no,
                'username' => (string) $merchant->settlement_account_name,
                'orders' => $totalOrders,
                'order_today' => $todayOrders,
                'order_lastday' => $lastDayOrders,
            ];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '查询失败')];
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
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '查询失败')];
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
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '查询失败')];
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

            return [
                'code' => 1,
                'msg' => '查询结算记录成功！',
                // 批量查询和单条查询共用同一套格式化器，避免字段口径不一致。
                'data' => array_map(function ($row): array {
                    return $this->formatEpayOrderRow($row, $this->bizOrderRepository->findByBizNo((string) $row->biz_no));
                }, $paginator->items()),
            ];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '查询失败')];
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
                return ['code' => 1, 'msg' => '订单不存在'];
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];
            $refundAmount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
            if ($refundAmount <= 0) {
                return ['code' => 1, 'msg' => '退款金额不合法'];
            }

            $refundOrder = $this->refundService->createRefund([
                'pay_no' => (string) $payOrder->pay_no,
                'merchant_refund_no' => trim((string) ($payload['refund_no'] ?? $payload['merchant_refund_no'] ?? '')),
                'refund_amount' => $refundAmount,
                'reason' => trim((string) ($payload['reason'] ?? '')),
                'ext_json' => ['source' => 'epay'],
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
                    'ext_json' => ['source' => 'epay'],
                ]);

                return ['code' => 1, 'msg' => (string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '退款失败')];
            }

            $this->refundService->markRefundSuccess((string) $refundOrder->refund_no, [
                'succeeded_at' => $this->now(),
                'channel_refund_no' => $this->resolveRefundChannelNo($pluginResult),
                'ext_json' => ['source' => 'epay'],
            ]);

            return ['code' => 0, 'msg' => '退款成功'];
        } catch (Throwable $e) {
            return ['code' => 1, 'msg' => $this->normalizeErrorMessage($e, '退款失败')];
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
        $normalized = $this->normalizeSubmitPayload($payload, $request);
        $result = $this->payOrderService->preparePayAttempt($normalized);
        $payOrder = $result['pay_order'];
        $payParams = (array) ($result['pay_params'] ?? []);

        return [
            'normalized_payload' => $normalized,
            'result' => $result,
            'pay_order' => $payOrder,
            'pay_params' => $payParams,
            'cashier_url' => $this->buildCashierUrl((string) $payOrder->pay_no),
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
    private function normalizeSubmitPayload(array $payload, Request $request): array
    {
        // 提交入口也必须先验签，避免旧协议请求绕过统一的身份校验。
        $this->merchantApiCredentialService->verifyMd5Sign($payload);
        $typeCode = trim((string) ($payload['type'] ?? ''));
        $merchantOrderNo = trim((string) ($payload['out_trade_no'] ?? ''));
        $subject = trim((string) ($payload['name'] ?? ''));
        $amount = $this->parseMoneyToAmount((string) ($payload['money'] ?? '0'));
        $paymentType = $this->resolveSubmitPaymentType($typeCode);

        if ($merchantOrderNo === '') {
            throw new ValidationException('out_trade_no 参数不能为空');
        }
        if ($subject === '') {
            throw new ValidationException('name 参数不能为空');
        }
        if ($amount <= 0) {
            throw new ValidationException('money 参数不合法');
        }

        $extJson = [
            'epay_type' => $typeCode,
            'resolved_type' => (string) $paymentType->code,
            'notify_url' => trim((string) ($payload['notify_url'] ?? '')),
            'return_url' => trim((string) ($payload['return_url'] ?? '')),
            'param' => $this->normalizePayloadValue($payload['param'] ?? null),
            'clientip' => $this->resolveClientIp($payload, $request),
            'device' => $this->normalizeDeviceCode((string) ($payload['device'] ?? 'pc')),
            'sign_type' => strtoupper((string) ($payload['sign_type'] ?? 'MD5')),
            'submitted_type' => $typeCode,
            'submit_mode' => $typeCode === '' ? 'cashier' : 'direct',
            'request_method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')),
            // 原始请求快照保留在扩展字段里，方便后续排查旧协议参数差异。
            'request_snapshot' => $this->normalizeRequestSnapshot($payload),
            'channel_callback_base_url' => (string) sys_config('site_url') . '/api/pay',
        ];

        return [
            'merchant_id' => (int) ($payload['pid'] ?? 0),
            'merchant_order_no' => $merchantOrderNo,
            'pay_type_id' => (int) $paymentType->id,
            'pay_amount' => $amount,
            'subject' => $subject,
            'body' => $subject,
            'ext_json' => $extJson,
        ];
    }

    /**
     * 解析提交支付方式。
     *
     * 空支付方式时，沿用当前系统默认启用支付方式；显式传值时必须是启用中的支付方式。
     *
     * @param string $typeCode 支付方式编码
     * @return PaymentType 支付方式模型
     * @throws ValidationException
     */
    private function resolveSubmitPaymentType(string $typeCode): PaymentType
    {
        $typeCode = trim($typeCode);
        if ($typeCode === '') {
            return $this->paymentTypeService->resolveEnabledType('');
        }

        $paymentType = $this->paymentTypeService->findByCode($typeCode);
        if (!$paymentType || (int) $paymentType->status !== 1) {
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
        $cashierUrl = (string) ($attempt['cashier_url'] ?? $this->buildCashierUrl((string) $payOrder->pay_no));
        $payNo = (string) $payOrder->pay_no;
        $response = ['code' => 1, 'msg' => '提交成功', 'trade_no' => $payNo];
        $type = (string) ($payParams['type'] ?? '');

        // 不同插件返回的支付承载形态不同，这里按旧协议常见字段逐个兼容。
        if ($type === 'qrcode') {
            $qrcode = $this->stringifyValue($payParams['qrcode_url'] ?? $payParams['qrcode_data'] ?? '');
            if ($qrcode !== '') {
                $response['qrcode'] = $qrcode;
                $response['payurl'] = $cashierUrl;
                return $response;
            }
        }

        if ($type === 'urlscheme') {
            $urlscheme = $this->stringifyValue($payParams['urlscheme'] ?? $payParams['order_str'] ?? '');
            if ($urlscheme !== '') {
                $response['urlscheme'] = $urlscheme;
                $response['payurl'] = $cashierUrl;
                return $response;
            }
        }

        if ($type === 'url') {
            $payUrl = $this->stringifyValue($payParams['payurl'] ?? '');
            if ($payUrl !== '') {
                $response['payurl'] = $cashierUrl;
                $response['origin_payurl'] = $payUrl;
                return $response;
            }
        }

        if ($type === 'form' && $this->stringifyValue($payParams['html'] ?? '') !== '') {
            // 表单类承载本身会把页面内容交给插件，这里仍然只回传收银台入口。
            $response['payurl'] = $cashierUrl;
            return $response;
        }

        if ($type === 'jsapi') {
            $urlscheme = $this->stringifyValue($payParams['urlscheme'] ?? $payParams['order_str'] ?? '');
            if ($urlscheme !== '') {
                $response['urlscheme'] = $urlscheme;
                $response['payurl'] = $cashierUrl;
                return $response;
            }
        }

        $fallback = $cashierUrl;
        if ($fallback !== '') {
            $response['payurl'] = $fallback;
        }

        return $response;
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
            'param' => $this->stringifyValue($extJson['param'] ?? ''),
            'buyer' => $this->stringifyValue($extJson['buyer'] ?? ''),
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
     * 解析商户结算类型。
     *
     * @param object $merchant 商户对象
     * @return int 结算类型编码
     */
    private function resolveMerchantSettlementType(mixed $merchant): int
    {
        // 旧 Epay 协议里结算类型是约定好的整数，这里用账户信息做一个兼容性映射。
        $bankName = strtolower(trim((string) ($merchant->settlement_bank_name ?? '')));
        $accountName = strtolower(trim((string) ($merchant->settlement_account_name ?? '')));
        $accountNo = strtolower(trim((string) ($merchant->settlement_account_no ?? '')));

        if (str_contains($accountName, '支付宝') || str_contains($bankName, 'alipay') || str_contains($accountNo, 'alipay')) {
            return 1;
        }

        if (str_contains($accountName, '微信') || str_contains($bankName, 'wechat') || str_contains($accountNo, 'wechat')) {
            return 2;
        }

        if (str_contains($accountName, 'qq') || str_contains($bankName, 'qq') || str_contains($accountNo, 'qq')) {
            return 3;
        }

        if ($bankName !== '' || $accountNo !== '') {
            return 4;
        }

        return 4;
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

        // 旧协议金额按“元”传入，内部统一转成“分”处理。
        return (int) round(((float) $money) * 100);
    }

    /**
     * 解析客户端 IP。
     *
     * 优先使用旧协议中的 `clientip`，缺省时回退到请求真实 IP。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return string 客户端 IP
     */
    private function resolveClientIp(array $payload, Request $request): string
    {
        $clientIp = trim((string) ($payload['clientip'] ?? ''));
        if ($clientIp !== '') {
            return $clientIp;
        }

        // 旧请求没传 clientip 时，退回到框架识别的真实 IP。
        return trim((string) $request->getRealIp());
    }

    /**
     * 归一化设备类型。
     *
     * @param string $device 设备编码
     * @return string 归一化后的设备编码
     */
    private function normalizeDeviceCode(string $device): string
    {
        $device = strtolower(trim($device));
        // 没传设备类型时默认按 pc 处理，兼容旧接口的页面跳转场景。
        return $device !== '' ? $device : 'pc';
    }

    /**
     * 归一化旧协议扩展参数。
     *
     * @param array|object|bool|float|int|string|null $value 扩展参数
     * @return array|string|null 归一化后的值
     */
    private function normalizePayloadValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $data = $value->toArray();
            return is_array($data) ? $data : null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * 生成请求快照。
     *
     * 快照会移除敏感签名字段，便于落库排障。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> 请求快照
     */
    private function normalizeRequestSnapshot(array $payload): array
    {
        $snapshot = $payload;
        // 签名字段和内部 submit_mode 不参与快照展示，避免误导排障。
        unset($snapshot['sign'], $snapshot['key']);
        unset($snapshot['submit_mode']);
        return $snapshot;
    }

    /**
     * 构建收银台跳转地址。
     *
     * @param string $payNo 支付单号
     * @return string 收银台 URL
     */
    private function buildCashierUrl(string $payNo): string
    {
        return (string) sys_config('site_url') . '/pay/' . rawurlencode($payNo) . '/payment';
    }

    /**
     * 规范化异常提示。
     *
     * @param Throwable $e 异常对象
     * @param string $fallback 默认文案
     * @return string 错误提示
     */
    private function normalizeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim((string) $e->getMessage());
        return $message !== '' ? $message : $fallback;
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
