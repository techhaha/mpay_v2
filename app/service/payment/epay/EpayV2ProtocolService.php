<?php

namespace app\service\payment\epay;

use app\common\base\BaseService;
use app\common\constant\AuthConstant;
use app\common\constant\CommonConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\PaymentIdentityConstant;
use app\common\constant\TradeConstant;
use app\common\util\FormatHelper;
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
use app\service\payment\order\RefundDispatchService;
use app\service\payment\order\RefundService;
use app\service\payment\transfer\TransferService;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\config\PaymentTypeService;
use support\Request;
use support\Response;
use Throwable;

/**
 * ePay V2 协议服务。
 *
 * 负责 V2 签名鉴权、支付、退款、查单、商户信息和转账接口的协议适配。
 */
class EpayV2ProtocolService extends BaseService
{
    private const SUCCESS_CODE = 0;
    private const FAILURE_CODE = 1;

    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @param MerchantApiCredentialRepository $merchantApiCredentialRepository 商户 API 凭证仓库
     * @param PaymentTypeService $paymentTypeService 支付类型服务
     * @param PayOrderService $payOrderService 支付订单服务
     * @param PayOrderQueryService $payOrderQueryService 支付订单查询服务
     * @param RefundService $refundService 退款服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param BizOrderRepository $bizOrderRepository 业务单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param MerchantAccountRepository $merchantAccountRepository 商户账户仓库
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param TransferService $transferService 转账服务
     * @param EpaySignerManager $signerManager ePay 签名管理器
     * @param EpaySubmitPayloadAssembler $submitPayloadAssembler 提交入参组装器
     * @param RefundDispatchService $refundDispatchService 退款派发服务
     * @return void
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected MerchantApiCredentialRepository $merchantApiCredentialRepository,
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderService $payOrderService,
        protected PayOrderQueryService $payOrderQueryService,
        protected RefundService $refundService,
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountRepository $merchantAccountRepository,
        protected PaymentPluginManager $paymentPluginManager,
        protected TransferService $transferService,
        protected EpaySignerManager $signerManager,
        protected EpaySubmitPayloadAssembler $submitPayloadAssembler,
        protected RefundDispatchService $refundDispatchService
    ) {
    }

    /**
     * 处理 V2 页面支付入口。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @param Request $request 请求对象
     * @return Response 跳转响应
     */
    public function submit(array $payload, Request $request): Response
    {
        try {
            $typeCode = trim((string) ($payload['type'] ?? ''));
            if ($typeCode === '') {
                // `type` 为空时先回收银台，显式选完方式后再创建支付单。
                $cashierUrl = $this->prepareCashierSubmit($payload, $request);
                if ($cashierUrl === '') {
                    throw new ValidationException('收银台跳转地址生成失败');
                }

                return redirect($cashierUrl);
            }

            $attempt = $this->preparePayAttempt(
                $payload,
                $request,
                EpayProtocolConstant::SUBMIT_TYPE_PAGE
            );

            if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
                return redirect((string) $attempt[PaymentIdentityConstant::FIELD_IDENTITY_URL]);
            }

            return redirect((string) $attempt['payment_page_url']);
        } catch (Throwable $e) {
            return $this->entryErrorResponse($payload, $e);
        }
    }

    /**
     * 构建页面支付入口级错误跳转。
     *
     * 支付单创建前没有 presentation 可承接，只能跳入口错误页；已创建支付单的异常继续回到支付承接页。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @param Throwable $e 异常
     * @return Response 跳转响应
     */
    public function entryErrorResponse(array $payload, Throwable $e): Response
    {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $payNo = trim((string) ($data['pay_no'] ?? ''));
        if ($payNo !== '') {
            return redirect($this->buildPaymentPageUrl($payNo));
        }

        return redirect($this->buildEntryErrorPageUrl($payload, $e));
    }

    /**
     * 处理 V2 API 支付下单入口。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @param Request $request 请求对象
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function create(array $payload, Request $request): array
    {
        try {
            $attempt = $this->preparePayAttempt(
                $payload,
                $request,
                EpayProtocolConstant::SUBMIT_TYPE_API
            );
            if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
                return $this->signResponse($this->buildIdentityCreateResponse($attempt));
            }

            /** @var PayOrder $payOrder */
            $payOrder = $attempt['pay_order'];
            $paymentResult = (array) ($attempt['payment_result'] ?? []);
            $payPage = strtolower(trim((string) ($paymentResult['pay_page'] ?? '')));
            $payAction = strtolower(trim((string) ($paymentResult['pay_action'] ?? $payPage)));
            $payParams = (array) ($attempt['pay_params'] ?? []);

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
                'msg' => 'success',
                'trade_no' => (string) $payOrder->pay_no,
                'pay_type' => $payAction,
                'pay_info' => $this->buildCreatePayInfo($payPage, $payParams),
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 查询 V2 支付订单。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function query(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $context = $this->resolvePayOrderContext((int) $merchant->id, $payload);
            if (!$context) {
                throw new ResourceNotFoundException('订单不存在');
            }

            return $this->signResponse($this->buildOrderResponse($context['pay_order'], $context['biz_order']));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 创建并同步派发 V2 退款。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function refund(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $context = $this->resolvePayOrderContext((int) $merchant->id, $payload);
            if (!$context) {
                throw new ResourceNotFoundException('订单不存在');
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];
            $merchantRefundNo = trim((string) ($payload['out_refund_no'] ?? $payload['refund_no'] ?? ''));
            $refundOrder = $this->refundService->createRefund([
                'pay_no' => (string) $payOrder->pay_no,
                'merchant_refund_no' => $merchantRefundNo,
                'refund_amount' => $this->parseMoneyToAmount((string) $payload['money']),
                'reason' => trim((string) ($payload['reason'] ?? '')),
            ]);

            $refundOrder = $this->refundDispatchService->dispatch($refundOrder);
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_SUCCESS) {
                throw new ValidationException((string) ($refundOrder->last_error ?: '退款失败'));
            }

            $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);
            return $this->signResponse($this->buildRefundResponse($refundOrder->refresh(), $payOrder->refresh(), $bizOrder));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 查询 V2 退款单。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function refundQuery(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $refundOrder = $this->resolveRefundOrder((int) $merchant->id, $payload);
            $payOrder = $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
            $bizOrder = $this->bizOrderRepository->findByBizNo((string) $refundOrder->biz_no);

            return $this->signResponse($this->buildRefundResponse($refundOrder, $payOrder, $bizOrder));
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 关闭 V2 支付订单。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function close(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $context = $this->resolvePayOrderContext((int) $merchant->id, $payload);
            if (!$context) {
                throw new ResourceNotFoundException('订单不存在');
            }

            /** @var PayOrder $payOrder */
            $payOrder = $context['pay_order'];
            $currentStatus = (int) $payOrder->status;
            if ($currentStatus === TradeConstant::ORDER_STATUS_CLOSED) {
                return $this->signResponse([
                    'code' => self::SUCCESS_CODE,
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

            if (array_key_exists('success', $pluginResult) && !(bool) $pluginResult['success']) {
                throw new ValidationException((string) ($pluginResult['msg'] ?? $pluginResult['message'] ?? '渠道关单失败'));
            }

            $closeReason = (string) ($pluginResult['msg'] ?? 'ePay V2 手动关闭');
            $this->payOrderService->closePayOrder((string) $payOrder->pay_no, [
                'closed_at' => $this->now(),
                'reason' => $closeReason,
            ]);

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
                'msg' => 'success',
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 查询 V2 商户账户和订单概览。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function merchantInfo(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $account = $this->merchantAccountRepository->findByMerchantId((int) $merchant->id);
            $today = FormatHelper::timestamp(time(), 'Y-m-d');
            $yesterday = FormatHelper::timestamp(strtotime('-1 day'), 'Y-m-d');

            $orderQuery = $this->payOrderRepository->query()->where('merchant_id', (int) $merchant->id);

            $totalOrders = (int) (clone $orderQuery)->count();
            $todayOrders = (int) (clone $orderQuery)->whereDate('created_at', $today)->count();
            $yesterdayOrders = (int) (clone $orderQuery)->whereDate('created_at', $yesterday)->count();
            $todayMoney = (int) (clone $orderQuery)->whereDate('created_at', $today)->sum('pay_amount');
            $yesterdayMoney = (int) (clone $orderQuery)->whereDate('created_at', $yesterday)->sum('pay_amount');

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
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
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 查询 V2 商户订单列表。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function merchantOrders(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $limit = (int) ($payload['limit'] ?? 20);
            $offset = (int) ($payload['offset'] ?? 0);
            $page = (int) floor($offset / $limit) + 1;
            $filters = [];
            if (array_key_exists('status', $payload) && $payload['status'] !== '') {
                $filters['status'] = (int) $payload['status'];
            }

            $result = $this->payOrderQueryService->paginate($filters, $page, $limit, (int) $merchant->id);

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
                'msg' => 'success',
                'data' => $result['list'] ?? [],
            ]);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 发起 V2 转账。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function transferSubmit(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $data = $this->transferService->submit($merchant, $payload);

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
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
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 查询 V2 转账单。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function transferQuery(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $data = $this->transferService->query($merchant, $payload);

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
                'msg' => 'success',
            ] + $data);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 查询 V2 转账余额。
     *
     * @param array<string, mixed> $payload 请求载荷
     * @return array<string, mixed> 签名后的 V2 响应
     */
    public function transferBalance(array $payload): array
    {
        try {
            $merchant = $this->authorizeMerchant($payload);
            $data = $this->transferService->balance($merchant);

            return $this->signResponse([
                'code' => self::SUCCESS_CODE,
                'msg' => 'success',
            ] + $data);
        } catch (Throwable $e) {
            return $this->signResponse([
                'code' => self::FAILURE_CODE,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }

    /**
     * 预创建支付。
     *
     * @param array $payload 请求参数
     * @param Request $request 请求对象
     * @return array<string, mixed>
     */
    private function preparePayAttempt(
        array $payload,
        Request $request,
        string $submitType
    ): array
    {
        $merchant = $this->authorizeMerchant($payload);
        $paymentType = $this->paymentTypeService->findByCode((string) $payload['type']);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new ValidationException('支付方式不支持');
        }

        $orderPayload = $payload;
        if ($submitType === EpayProtocolConstant::SUBMIT_TYPE_PAGE) {
            $orderPayload['device'] = $this->submitPayloadAssembler->resolvePageSubmitDevice(
                $payload,
                $request,
                EpayProtocolConstant::v2Devices()
            );
        }

        // V2 协议入口在这里统一转换为支付发起服务的标准入参。
        $orderFields = $this->submitPayloadAssembler->buildOrderFields($orderPayload, $request, [
            '_protocol_version' => EpayProtocolConstant::VERSION_V2,
            '_submit_type' => $submitType,
        ]);
        $normalized = [
            'merchant_id' => (int) $merchant->id,
            'merchant_order_no' => trim((string) $payload['out_trade_no']),
            'pay_type_id' => (int) $paymentType->id,
            'pay_amount' => $this->parseMoneyToAmount((string) $payload['money']),
            'subject' => (string) $orderFields['subject'],
            'body' => (string) $orderFields['body'],
            'notify_url' => (string) $orderFields['notify_url'],
            'return_url' => (string) $orderFields['return_url'],
            'client_ip' => (string) $orderFields['client_ip'],
            'device' => (string) $orderFields['device'],
            'channel_id' => (int) ($payload['channel_id'] ?? 0),
            'ext_json' => (array) $orderFields['ext_json'],
            'identity_flow' => true,
        ];

        $attempt = $this->payOrderService->preparePayAttempt($normalized);
        if (($attempt['status'] ?? '') === PaymentIdentityConstant::STATUS_REQUIRED) {
            return $attempt;
        }

        $payOrder = $attempt['pay_order'];

        return [
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
     * @return string 收银台地址
     */
    private function prepareCashierSubmit(array $payload, Request $request): string
    {
        $merchant = $this->authorizeMerchant($payload);

        $orderPayload = $payload;
        $orderPayload['device'] = $this->submitPayloadAssembler->resolvePageSubmitDevice(
            $payload,
            $request,
            EpayProtocolConstant::v2Devices()
        );

        // 收银台首屏只需要业务单上下文，不在这里创建支付单。
        $orderFields = $this->submitPayloadAssembler->buildOrderFields($orderPayload, $request, [
            '_protocol_version' => EpayProtocolConstant::VERSION_V2,
            '_submit_type' => EpayProtocolConstant::SUBMIT_TYPE_PAGE,
        ]);
        $normalized = [
            'merchant_id' => (int) $merchant->id,
            'merchant_order_no' => trim((string) $payload['out_trade_no']),
            'pay_amount' => $this->parseMoneyToAmount((string) $payload['money']),
            'subject' => (string) $orderFields['subject'],
            'body' => (string) $orderFields['body'],
            'notify_url' => (string) $orderFields['notify_url'],
            'return_url' => (string) $orderFields['return_url'],
            'client_ip' => (string) $orderFields['client_ip'],
            'device' => (string) $orderFields['device'],
            'ext_json' => (array) $orderFields['ext_json'],
        ];

        $result = $this->payOrderService->prepareCashierBizOrder($normalized);

        return (string) ($result['cashier_url'] ?? '');
    }

    /**
     * V2 API 返回协议字段，不能暴露承接页内部的 `page/_page` 结构。
     *
     * @param string $payPage 承接页类型
     * @param array<string, mixed> $payParams 插件支付参数
     * @return mixed V2 pay_info
     */
    private function buildCreatePayInfo(string $payPage, array $payParams): mixed
    {
        return match ($payPage) {
            'qrcode' => (string) ($payParams['qrcode'] ?? ''),
            'html' => (string) ($payParams['html'] ?? ''),
            'jump' => (string) ($payParams['url'] ?? ''),
            'urlscheme' => (string) ($payParams['urlscheme'] ?? ''),
            'jsapi' => array_diff_key($payParams, ['raw' => true]),
            'page' => $payParams['params'] ?? array_diff_key($payParams, ['_page' => true, 'raw' => true]),
            default => array_diff_key($payParams, ['raw' => true]),
        };
    }

    /**
     * 构建需要用户授权时的 V2 创建订单响应。
     *
     * @param array<string, mixed> $attempt 身份流程结果
     * @return array<string, mixed> V2 响应
     */
    private function buildIdentityCreateResponse(array $attempt): array
    {
        $identityUrl = (string) ($attempt[PaymentIdentityConstant::FIELD_IDENTITY_URL] ?? '');

        return [
            'code' => self::SUCCESS_CODE,
            'msg' => PaymentIdentityConstant::STATUS_REQUIRED,
            'trade_no' => '',
            'pay_type' => 'identity',
            'pay_info' => $identityUrl,
            PaymentIdentityConstant::FIELD_REQUIRED => 1,
            PaymentIdentityConstant::FIELD_IDENTITY_URL => $identityUrl,
            PaymentIdentityConstant::FIELD_RESUME_TOKEN => (string) ($attempt[PaymentIdentityConstant::FIELD_RESUME_TOKEN] ?? ''),
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

        $refundOrder = $this->refundOrderRepository->findByMerchantRefundNo($merchantId, $outRefundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['out_refund_no' => $outRefundNo]);
        }

        return $refundOrder;
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
            'code' => self::SUCCESS_CODE,
            'msg' => 'success',
            'trade_no' => (string) $payOrder->pay_no,
            'out_trade_no' => (string) ($bizOrder?->merchant_order_no ?? ''),
            'api_trade_no' => (string) ($payOrder->channel_trade_no ?: $payOrder->channel_order_no ?: ''),
            'type' => $this->paymentTypeService->resolveCodeById((int) $payOrder->pay_type_id),
            'status' => (int) $payOrder->status === TradeConstant::ORDER_STATUS_SUCCESS ? ($refundAmount > 0 ? 2 : 1) : 0,
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
            'code' => self::SUCCESS_CODE,
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
     * 认证商户并校验请求签名。
     *
     * @param array $payload 请求参数
     * @return Merchant
     */
    private function authorizeMerchant(array $payload): Merchant
    {
        $merchantId = (int) $payload['pid'];
        $merchant = $this->merchantService->ensureMerchantEnabled($merchantId);
        $credential = $this->merchantApiCredentialRepository->findByMerchantId($merchantId);
        if (!$credential || (int) $credential->status !== AuthConstant::CREDENTIAL_STATUS_ENABLED) {
            throw new ValidationException('商户 API 凭证未开通');
        }

        $publicKey = trim((string) ($credential->merchant_public_key ?? ''));
        if ($publicKey === '') {
            throw new ValidationException('商户 RSA 公钥未配置');
        }

        if (abs(time() - (int) $payload['timestamp']) > (int) config('epay.v2.timestamp_ttl', 300)) {
            throw new ValidationException('timestamp 校验失败');
        }

        $verifyPayload = $payload;
        unset($verifyPayload['sign'], $verifyPayload['sign_type']);

        if (!$this->signerManager->verify($verifyPayload, (string) $payload['sign_type'], (string) $payload['sign'], $publicKey)) {
            throw new ValidationException('签名验证失败');
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
        $data['sign_type'] = AuthConstant::API_SIGN_NAME_RSA;
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
     * 构建支付入口错误页地址。
     *
     * @param array<string, mixed> $payload 原始请求载荷
     * @param Throwable $e 异常
     * @return string 错误页地址
     */
    private function buildEntryErrorPageUrl(array $payload, Throwable $e): string
    {
        $query = [
            'msg' => $this->safeEntryErrorMessage($e),
        ];

        $code = (string) $e->getCode();
        if ($code !== '' && $code !== '0') {
            $query['code'] = $code;
        }

        foreach (['out_trade_no', 'pid'] as $key) {
            if (($payload[$key] ?? '') !== '') {
                $query[$key] = (string) $payload[$key];
            }
        }

        return rtrim((string) sys_config('site_url'), '/') . '/payment/entry/error?' . http_build_query($query);
    }

    /**
     * 生成可展示的入口错误信息。
     *
     * @param Throwable $e 异常
     * @return string 安全错误信息
     */
    private function safeEntryErrorMessage(Throwable $e): string
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($e->getMessage())) ?? '');
        if ($message === '') {
            $message = '支付发起失败，请检查请求参数或商户配置';
        }

        return mb_strcut($message, 0, 240, 'UTF-8');
    }

}
