<?php

namespace app\service\payment\epay;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\PaymentIdentityConstant;
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
use app\service\payment\order\RefundDispatchService;
use app\service\payment\order\RefundService;
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
 * @property EpaySubmitPayloadAssembler $submitPayloadAssembler 提交入参组装器
 * @property MerchantAccountRepository $merchantAccountRepository 商户账户仓库
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property SettlementOrderRepository $settlementOrderRepository 结算订单仓库
 * @property RefundService $refundService 退款服务
 */
class EpayV1ProtocolService extends BaseService
{
    private const SUCCESS_CODE = 1;
    private const FAILURE_CODE = 0;

    /**
     * 构造方法。
     *
     * @param MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
     * @param PaymentTypeService $paymentTypeService 支付类型服务
     * @param PayOrderService $payOrderService 支付订单服务
     * @param MerchantAccountRepository $merchantAccountRepository 商户账户仓库
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param SettlementOrderRepository $settlementOrderRepository 结算订单仓库
     * @param RefundService $refundService 退款服务
     * @param RefundDispatchService $refundDispatchService 退款派发服务
     * @return void
     */
    public function __construct(
        protected MerchantApiCredentialService $merchantApiCredentialService,
        protected MerchantService $merchantService,
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderService $payOrderService,
        protected EpaySubmitPayloadAssembler $submitPayloadAssembler,
        protected EpaySignerManager $epaySignerManager,
        protected MerchantAccountRepository $merchantAccountRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected SettlementOrderRepository $settlementOrderRepository,
        protected RefundService $refundService,
        protected RefundDispatchService $refundDispatchService
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
                $targetUrl = $this->prepareCashierSubmit($payload, $request);
                if ($targetUrl === '') {
                    throw new ValidationException('收银台跳转地址生成失败');
                }

                return redirect($targetUrl);
            }

            $attempt = $this->prepareSubmitAttempt(
                $payload,
                $request,
                EpayProtocolConstant::SUBMIT_TYPE_PAGE
            );

            if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
                return redirect((string) $attempt[PaymentIdentityConstant::FIELD_IDENTITY_URL]);
            }

            return redirect((string) $attempt['payment_page_url']);
        } catch (Throwable $e) {
            $data = method_exists($e, 'getData') ? $e->getData() : [];
            $payNo = trim((string) ($data['pay_no'] ?? ''));
            if ($payNo !== '') {
                return redirect($this->buildPaymentPageUrl($payNo));
            }

            return json([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
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
            $attempt = $this->prepareSubmitAttempt($payload, $request, EpayProtocolConstant::SUBMIT_TYPE_API);
            return $this->buildMapiResponse($attempt);
        } catch (Throwable $e) {
            return ['code' => self::FAILURE_CODE, 'msg' => $e->getMessage() ?: '请求失败'];
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
    private function queryMerchantInfo(array $payload): array
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
                'code' => self::SUCCESS_CODE,
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
            return ['code' => self::FAILURE_CODE, 'msg' => $e->getMessage() ?: '请求失败'];
        }
    }

    /**
     * 查询结算记录列表，对应 `act=settle`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    private function querySettlementList(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $rows = $this->settlementOrderRepository->query()->where('merchant_id', $merchantId)->orderByDesc('id')->get();

            // 旧协议列表只需要基础字段和金额文本，这里直接整理成可展示数组。
            return [
                'code' => self::SUCCESS_CODE,
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
            return ['code' => self::FAILURE_CODE, 'msg' => $e->getMessage() ?: '请求失败'];
        }
    }

    /**
     * 查询单个订单，对应 `act=order`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    private function queryOrder(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $context = $this->resolvePayOrderContext($merchantId, $payload);
            if (!$context) {
                return ['code' => self::FAILURE_CODE, 'msg' => '订单不存在'];
            }

            // 旧协议查询单号时，要把支付单和业务单合并成同一份响应结构。
            return ['code' => self::SUCCESS_CODE, 'msg' => '查询订单号成功！'] + $this->formatEpayOrderRow($context['pay_order'], $context['biz_order']);
        } catch (Throwable $e) {
            return ['code' => self::FAILURE_CODE, 'msg' => $e->getMessage() ?: '请求失败'];
        }
    }

    /**
     * 批量查询订单，对应 `act=orders`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    private function queryOrders(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $limit = (int) ($payload['limit'] ?? 20);
            $page = (int) ($payload['page'] ?? 1);
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
                'code' => self::SUCCESS_CODE,
                'msg' => '查询结算记录成功！',
                // 批量查询和单条查询共用同一套格式化器，避免字段口径不一致。
                'data' => array_map(function ($row) use ($bizOrderMap): array {
                    $bizNo = (string) ($row->biz_no ?? '');

                    return $this->formatEpayOrderRow($row, $bizOrderMap[$bizNo] ?? null);
                }, $items),
            ];
        } catch (Throwable $e) {
            return ['code' => self::FAILURE_CODE, 'msg' => $e->getMessage() ?: '请求失败'];
        }
    }

    /**
     * 提交退款申请，对应 `act=refund`。
     *
     * @param array $payload 请求载荷
     * @return array<string, mixed> ePay 风格响应
     */
    private function createRefund(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            // 先确认退款目标单据归属当前商户，避免旧协议拿着别人的单号误发退款。
            $context = $this->resolvePayOrderContext($merchantId, $payload);
            if (!$context) {
                return ['code' => self::FAILURE_CODE, 'msg' => '订单不存在'];
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];

            $refundOrder = $this->refundService->createRefund([
                'pay_no' => (string) $payOrder->pay_no,
                'merchant_refund_no' => trim((string) ($payload['refund_no'] ?? $payload['merchant_refund_no'] ?? '')),
                'refund_amount' => $this->parseMoneyToAmount((string) $payload['money']),
                'reason' => trim((string) ($payload['reason'] ?? '')),
            ]);

            $refundOrder = $this->refundDispatchService->dispatch($refundOrder);
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_SUCCESS) {
                return ['code' => self::FAILURE_CODE, 'msg' => (string) ($refundOrder->last_error ?: '退款失败')];
            }

            return ['code' => self::SUCCESS_CODE, 'msg' => '退款成功'];
        } catch (Throwable $e) {
            return ['code' => self::FAILURE_CODE, 'msg' => $e->getMessage() ?: '请求失败'];
        }
    }

    /**
     * 预处理支付提交请求。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return array<string, mixed> 预处理数据
     */
    private function prepareSubmitAttempt(array $payload, Request $request, string $submitType): array
    {
        $merchantId = $this->authorizeSubmitMerchant($payload);
        $paymentType = $this->paymentTypeService->findByCode((string) $payload['type']);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('支付方式不支持');
        }

        $normalized = $this->buildSubmitPayload($payload, $request, $merchantId, $submitType, $paymentType);
        $result = $this->payOrderService->preparePayAttempt($normalized);
        if (($result['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
            return $result;
        }

        $payOrder = $result['pay_order'];
        $payParams = (array) ($result['pay_params'] ?? []);

        return [
            'pay_order' => $payOrder,
            'pay_params' => $payParams,
            'payment_result' => $result['payment_result'] ?? [],
            'payment_page_url' => $this->buildPaymentPageUrl((string) $payOrder->pay_no),
        ];
    }

    /**
     * 预创建收银台业务单。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @return string 收银台地址
     */
    private function prepareCashierSubmit(array $payload, Request $request): string
    {
        $merchantId = $this->authorizeSubmitMerchant($payload);
        $normalized = $this->buildSubmitPayload($payload, $request, $merchantId, EpayProtocolConstant::SUBMIT_TYPE_PAGE);
        $result = $this->payOrderService->prepareCashierBizOrder($normalized);

        return (string) ($result['cashier_url'] ?? '');
    }

    /**
     * 认证 V1 提交商户并校验签名。
     *
     * @param array $payload 请求载荷
     * @return int 商户ID
     */
    private function authorizeSubmitMerchant(array $payload): int
    {
        $merchantId = (int) $payload['pid'];
        $credential = $this->merchantApiCredentialService->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::CREDENTIAL_STATUS_ENABLED) {
            throw new ValidationException('商户 API 凭证未开通');
        }

        if (!$this->epaySignerManager->verify(
            $this->buildV1SignParams($payload),
            strtoupper((string) $payload['sign_type']),
            (string) $payload['sign'],
            (string) $credential->api_key
        )) {
            throw new ValidationException('签名验证失败');
        }

        $this->merchantService->ensureMerchantEnabled($merchantId);

        return $merchantId;
    }

    /**
     * 构建当前支付单创建参数。
     *
     * @param array $payload 请求载荷
     * @param Request $request 请求对象
     * @param int $merchantId 商户ID
     * @param string $submitType 提交类型
     * @param PaymentType|null $paymentType 支付方式，收银台首屏为空
     * @return array<string, mixed>
     */
    private function buildSubmitPayload(
        array $payload,
        Request $request,
        int $merchantId,
        string $submitType,
        ?PaymentType $paymentType = null
    ): array {
        $orderPayload = $payload;
        if ($submitType === EpayProtocolConstant::SUBMIT_TYPE_PAGE) {
            $orderPayload['device'] = $this->submitPayloadAssembler->resolvePageSubmitDevice(
                $payload,
                $request,
                EpayProtocolConstant::v1Devices()
            );
        }

        // 旧协议的展示字段统一交给 assembler，避免 submit / mapi / cashier 三处口径漂移。
        $orderFields = $this->submitPayloadAssembler->buildOrderFields($orderPayload, $request, [
            '_protocol_version' => EpayProtocolConstant::VERSION_V1,
            '_submit_type' => $submitType,
        ]);

        $normalized = [
            'merchant_id' => $merchantId,
            'merchant_order_no' => trim((string) $payload['out_trade_no']),
            'pay_amount' => $this->parseMoneyToAmount((string) $payload['money']),
            'subject' => (string) $orderFields['subject'],
            'body' => (string) $orderFields['body'],
            'notify_url' => (string) $orderFields['notify_url'],
            'return_url' => (string) $orderFields['return_url'],
            'client_ip' => (string) $orderFields['client_ip'],
            'device' => (string) $orderFields['device'],
            'ext_json' => (array) $orderFields['ext_json'],
            'identity_flow' => true,
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
     * 构建旧版 MAPI 返回结构。
     *
     * 根据当前支付尝试结果，输出 payurl、qrcode 或 urlscheme 等旧协议字段。
     *
     * @param array $attempt 支付尝试结果
     * @return array<string, mixed> ePay 风格响应
     */
    private function buildMapiResponse(array $attempt): array
    {
        if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
            $identityUrl = (string) ($attempt[PaymentIdentityConstant::FIELD_IDENTITY_URL] ?? '');

            return [
                'code' => self::SUCCESS_CODE,
                'msg' => '需要用户授权',
                'trade_no' => '',
                'payurl' => $identityUrl,
                'url' => $identityUrl,
                PaymentIdentityConstant::FIELD_REQUIRED => 1,
                PaymentIdentityConstant::FIELD_RESUME_TOKEN => (string) ($attempt[PaymentIdentityConstant::FIELD_RESUME_TOKEN] ?? ''),
            ];
        }

        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $paymentResult = (array) ($attempt['payment_result'] ?? []);
        $paymentPageUrl = (string) ($attempt['payment_page_url'] ?? $this->buildPaymentPageUrl((string) $payOrder->pay_no));
        $payNo = (string) $payOrder->pay_no;
        $response = ['code' => self::SUCCESS_CODE, 'msg' => '提交成功', 'trade_no' => $payNo];
        $device = strtolower(trim((string) ($payOrder->device ?? '')));
        $type = strtolower(trim((string) ($paymentResult['pay_page'] ?? '')));

        if ($device === 'jump') {
            $response['payurl'] = $paymentPageUrl;

            return $response;
        }

        if ($type === 'qrcode') {
            $qrcode = $this->stringifyValue($payParams['qrcode'] ?? '');
            if ($qrcode !== '') {
                $response['qrcode'] = $qrcode;

                return $response;
            }
        }

        if ($type === 'jump') {
            $url = $this->stringifyValue($payParams['url'] ?? '');
            if ($url !== '') {
                $response['payurl'] = $url;

                return $response;
            }
        }

        if ($type === 'urlscheme') {
            $urlscheme = $this->stringifyValue($payParams['urlscheme'] ?? '');
            if ($urlscheme !== '') {
                $response['urlscheme'] = $urlscheme;

                return $response;
            }
        }

        $response['payurl'] = $paymentPageUrl;

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
        $merchantExt = (array) ($extJson['merchant'] ?? []);

        return [
            'trade_no' => (string) $payOrder->pay_no,
            'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? ''),
            'api_trade_no' => (string) ($payOrder->channel_trade_no ?: $payOrder->channel_order_no ?: ''),
            'type' => $this->paymentTypeService->resolveCodeById((int) $payOrder->pay_type_id),
            'pid' => (int) $payOrder->merchant_id,
            'addtime' => FormatHelper::dateTime($payOrder->created_at),
            'endtime' => FormatHelper::dateTime($payOrder->paid_at),
            'name' => (string) ($bizOrder?->subject ?? ''),
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
