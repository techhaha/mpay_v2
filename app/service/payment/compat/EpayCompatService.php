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

class EpayCompatService extends BaseService
{
    private const API_ACTIONS = ['query', 'settle', 'order', 'orders', 'refund'];

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

    public function mapi(array $payload, Request $request): array
    {
        try {
            $attempt = $this->prepareSubmitAttempt($payload, $request);
            return $this->buildMapiResponse($attempt);
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '提交失败')];
        }
    }

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

    public function queryMerchantInfo(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $auth = $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $merchant = $auth['merchant'];
            $credential = $auth['credential'];
            $account = $this->merchantAccountRepository->findByMerchantId($merchantId);
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

    public function querySettlementList(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $rows = $this->settlementOrderRepository->query()->where('merchant_id', $merchantId)->orderByDesc('id')->get();

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

            return ['code' => 1, 'msg' => '查询订单号成功！'] + $this->formatEpayOrderRow($context['pay_order'], $context['biz_order']);
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '查询失败')];
        }
    }

    public function queryOrders(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
            $limit = min(50, max(1, (int) ($payload['limit'] ?? 20)));
            $page = max(1, (int) ($payload['page'] ?? 1));
            $paginator = $this->payOrderRepository->query()->where('merchant_id', $merchantId)->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);

            return [
                'code' => 1,
                'msg' => '查询结算记录成功！',
                'data' => array_map(function ($row): array {
                    return $this->formatEpayOrderRow($row, $this->bizOrderRepository->findByBizNo((string) $row->biz_no));
                }, $paginator->items()),
            ];
        } catch (Throwable $e) {
            return ['code' => 0, 'msg' => $this->normalizeErrorMessage($e, '查询失败')];
        }
    }

    public function createRefund(array $payload): array
    {
        try {
            $merchantId = (int) ($payload['pid'] ?? 0);
            $key = trim((string) ($payload['key'] ?? ''));
            $this->merchantApiCredentialService->authenticateByKey($merchantId, $key);
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

    private function prepareSubmitAttempt(array $payload, Request $request): array
    {
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

    private function normalizeSubmitPayload(array $payload, Request $request): array
    {
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

    private function buildMapiResponse(array $attempt): array
    {
        /** @var PayOrder $payOrder */
        $payOrder = $attempt['pay_order'];
        $payParams = (array) ($attempt['pay_params'] ?? []);
        $cashierUrl = (string) ($attempt['cashier_url'] ?? $this->buildCashierUrl((string) $payOrder->pay_no));
        $payNo = (string) $payOrder->pay_no;
        $response = ['code' => 1, 'msg' => '提交成功', 'trade_no' => $payNo];
        $type = (string) ($payParams['type'] ?? '');

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

        return ['pay_order' => $payOrder, 'biz_order' => $bizOrder];
    }

    private function resolvePaymentTypeCode(int $payTypeId): string
    {
        return $this->paymentTypeService->resolveCodeById($payTypeId);
    }

    private function resolveMerchantSettlementType(mixed $merchant): int
    {
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

    private function parseMoneyToAmount(string $money): int
    {
        $money = trim($money);
        if ($money === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $money)) {
            return 0;
        }

        return (int) round(((float) $money) * 100);
    }

    private function resolveClientIp(array $payload, Request $request): string
    {
        $clientIp = trim((string) ($payload['clientip'] ?? ''));
        if ($clientIp !== '') {
            return $clientIp;
        }

        return trim((string) $request->getRealIp());
    }

    private function normalizeDeviceCode(string $device): string
    {
        $device = strtolower(trim($device));
        return $device !== '' ? $device : 'pc';
    }

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

    private function normalizeRequestSnapshot(array $payload): array
    {
        $snapshot = $payload;
        unset($snapshot['sign'], $snapshot['key']);
        unset($snapshot['submit_mode']);
        return $snapshot;
    }

    private function buildCashierUrl(string $payNo): string
    {
        return (string) sys_config('site_url') . '/pay/' . rawurlencode($payNo) . '/payment';
    }

    private function normalizeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim((string) $e->getMessage());
        return $message !== '' ? $message : $fallback;
    }

    private function isPluginSuccess(array $pluginResult): bool
    {
        return !array_key_exists('success', $pluginResult) || (bool) $pluginResult['success'];
    }

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
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json !== false ? $json : '';
        }
        return (string) $value;
    }

}
