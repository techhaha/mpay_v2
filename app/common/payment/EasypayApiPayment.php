<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\easypay\EasypayClient;
use app\common\sdk\easypay\EasypaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use JsonException;
use support\Request;
use support\Response;

/**
 * 易生易企通支付 API 插件。
 *
 * 适配机构/商户两种接入档案，提供支付宝、微信和银联的 JSAPI/主扫支付、查单与退款。
 * 下单时保存 transDate、接入档案、商户号和产品快照，后续查单、退款及通知均复用这些
 * 上下文，避免根据当前配置猜测原交易；主动关单不在当前适配能力范围内。
 */
class EasypayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALI_PAY_JSAPI = 'AliPayJsapi';
    private const PRODUCT_WE_CHAT_JSAPI = 'WeChatJsapi';
    private const PRODUCT_UNION_PAY_JSAPI = 'UnionPayJsapi';
    private const PRODUCT_ALI_PAY_NATIVE = 'AliPayNative';
    private const PRODUCT_WE_CHAT_NATIVE = 'WeChatNative';
    private const PRODUCT_UNION_PAY_NATIVE = 'UnionPayNative';

    private const PRODUCTS = [
        self::PRODUCT_ALI_PAY_JSAPI,
        self::PRODUCT_WE_CHAT_JSAPI,
        self::PRODUCT_UNION_PAY_JSAPI,
        self::PRODUCT_ALI_PAY_NATIVE,
        self::PRODUCT_WE_CHAT_NATIVE,
        self::PRODUCT_UNION_PAY_NATIVE,
    ];

    private const PAYMENT_FAILURE_STATES = ['X', 'A', 'C', 'R', 'E'];

    private ?EasypayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'easypay_api',
        'name' => '易生易企通支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.easypay.com.cn/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造易生支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付单仓库；未注入时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
    }

    /**
     * 初始化支付插件。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;
        $this->validateConfiguration();
    }

    /**
     * 获取插件配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'radio',
                'field' => 'req_type',
                'title' => '接入模式',
                'value' => '2',
                'options' => [
                    ['label' => '机构模式', 'value' => '2'],
                    ['label' => '商户模式', 'value' => '1'],
                ],
                'validate' => [['required' => true, 'message' => '接入模式不能为空']],
            ],
            $this->inputField('req_id', '机构号/直连商户号', true),
            $this->inputField('sub_merchant_no', '机构模式子商户号'),
            $this->inputField('certificate_id', '证书 ID（多证书时填写）'),
            [
                'type' => 'upload',
                'field' => 'platform_public_key_path',
                'title' => '易生公钥/公钥证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [['required' => true, 'message' => '易生公钥文件不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_private_key_path',
                'title' => '商户 RSA 私钥',
                'value' => '',
                'props' => $this->uploadProps('.key,.pem'),
                'validate' => [['required' => true, 'message' => '商户 RSA 私钥文件不能为空']],
            ],
            $this->inputField('wx_mp_app_id', '微信公众号 AppID'),
            ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            $this->inputField('alipay_oauth_app_id', '支付宝生活号授权 AppID'),
            [
                'type' => 'upload',
                'field' => 'alipay_oauth_private_key_path',
                'title' => '支付宝授权应用私钥',
                'value' => '',
                'props' => $this->uploadProps('.key,.pem'),
            ],
            [
                'type' => 'upload',
                'field' => 'alipay_oauth_public_key_path',
                'title' => '支付宝公钥',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
            ],
            $this->inputField('unionpay_app_up_identifier', '银联 appUpIdentifier'),
            $this->enabledProductsField([
                self::PRODUCT_ALI_PAY_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WE_CHAT_JSAPI => '微信公众号 JSAPI',
                self::PRODUCT_UNION_PAY_JSAPI => '银联 JSAPI',
                self::PRODUCT_ALI_PAY_NATIVE => '支付宝主扫',
                self::PRODUCT_WE_CHAT_NATIVE => '微信主扫（rainbow_legacy）',
                self::PRODUCT_UNION_PAY_NATIVE => '银联主扫',
            ]),
            ['type' => 'switch', 'field' => 'sandbox', 'title' => '测试环境', 'value' => false],
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        return $this->executeDirectPaymentProduct($order, $this->paymentHandlers($order), '易生易企通');
    }

    /**
     * 声明下单产品所需的用户身份。
     *
     * 身份判断与下单共用相同产品开关和环境候选，避免授权完成后切换到另一支付产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；当前产品无需身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $handlers = $this->directPaymentUsableHandlers($order, $this->paymentHandlers($order));
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));
        $candidate = (string) ($candidates[0] ?? '');
        if (!in_array($candidate, ['jsapi', 'jump'], true)) {
            return null;
        }

        return match ((string) ($order['pay_type_code'] ?? '')) {
            'alipay' => $this->alipayIdentityRequirement($order),
            'wxpay' => $this->wechatIdentityRequirement($order),
            'bank' => $this->unionpayIdentityRequirement($order),
            default => null,
        };
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '易生查单缺少 pay_no');
        $channelTradeNo = $this->requiredText($order['chan_trade_no'] ?? '', '易生查单缺少原 outTrace');
        $transDate = $this->originalTransDate($order, '查单');
        $payProduct = $this->orderProduct($order, '查单');

        $data = $this->execute('/trade/tradeQuery', [
            'reqInfo' => ['mchtCode' => $this->mchtCode()],
            'reqOrderInfo' => [
                'orgTrace' => $this->operationTrace('Q'),
                'oriOrgTrace' => $payNo,
                'oriOutTrace' => $channelTradeNo,
                'oriTransDate' => $transDate,
            ],
            'payInfo' => ['payType' => $payProduct, 'transDate' => date('Ymd')],
        ], '查单');
        $this->assertBusinessAccepted($data, '查单');

        $state = $this->requiredText($data['respStateInfo']['transState'] ?? '', '易生查单响应缺少 transState');
        $orderInfo = $this->responseOrderInfo($data, '查单');
        $this->assertSameText($payNo, $orderInfo['orgTrace'] ?? '', '易生查单商户订单号不一致', true);
        $this->assertSameText($channelTradeNo, $orderInfo['outTrace'] ?? '', '易生查单 outTrace 不一致', true);
        $amount = $this->integerCents($orderInfo['transAmount'] ?? null, '易生查单金额');
        if ($amount !== (int) ($order['amount'] ?? 0)) {
            throw new PaymentUncertainException('易生查单金额与本地支付单不一致', 40200);
        }

        $status = match ($state) {
            '0', '2' => PaymentPluginStatusConstant::SUCCESS,
            '9' => PaymentPluginStatusConstant::PENDING,
            'A', 'C' => PaymentPluginStatusConstant::CLOSED,
            'X', 'E' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $channelTradeNo,
            'message' => trim((string) ($data['respStateInfo']['transStatusDesc'] ?? '')),
        ];
    }

    /**
     * 易生支付当前不支持主动关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('易生插件暂不支持关单', 40200);
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '易生退款缺少 pay_no');
        $refundNo = $this->requiredText($order['refund_no'] ?? '', '易生退款缺少 refund_no');
        $channelTradeNo = $this->requiredText($order['chan_trade_no'] ?? '', '易生退款缺少原 outTrace');
        $transDate = $this->originalTransDate($order, '退款');
        $this->orderProduct($order, '退款');
        $refundAmount = $this->positiveCents($order['refund_amount'] ?? 0, '易生退款金额无效');
        $reqOrderInfo = [
            'orgTrace' => $refundNo,
            'oriOrgTrace' => $payNo,
            'oriOutTrace' => $channelTradeNo,
            'oriTransDate' => $transDate,
            'refundAmount' => $refundAmount,
        ];
        $data = $this->execute('/trade/refund/apply', [
            'reqInfo' => ['mchtCode' => $this->mchtCode()],
            'reqOrderInfo' => $reqOrderInfo,
            'payInfo' => ['transDate' => date('Ymd')],
        ], '退款');
        $this->assertBusinessAccepted($data, '退款');

        $state = $this->requiredText($data['respStateInfo']['transState'] ?? '', '易生退款响应缺少 transState');
        if ($state === 'X') {
            throw new PaymentDefinitiveException('易生退款失败：' . $this->stateMessage($data), 40200);
        }

        $status = match ($state) {
            '0', '2' => PaymentPluginStatusConstant::SUCCESS,
            '9' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
        $orderInfo = $this->responseOrderInfo($data, '退款');
        $this->assertSameText($refundNo, $orderInfo['orgTrace'] ?? '', '易生退款请求号不一致', true);
        if (trim((string) ($orderInfo['oriOrgTrace'] ?? '')) !== '') {
            $this->assertSameText($payNo, $orderInfo['oriOrgTrace'], '易生退款原商户订单号不一致', true);
        }
        $actualAmount = $this->integerCents($orderInfo['refundAmount'] ?? null, '易生退款响应金额');
        if ($actualAmount !== $refundAmount) {
            throw new PaymentUncertainException('易生退款响应金额不一致', 40200);
        }
        $channelRefundNo = trim((string) ($orderInfo['outTrace'] ?? ''));
        if ($status === PaymentPluginStatusConstant::SUCCESS && $channelRefundNo === '') {
            throw new PaymentUncertainException('易生退款成功响应缺少真实 outTrace', 40200);
        }

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'message' => $this->stateMessage($data),
        ];
    }

    /**
     * 校验并解析支付通知。
     *
     * 使用 reqHeader、reqBody 与 reqSign 验签，并核对接入档案、商户号、通道、金额、
     * 渠道流水和下单产品上下文后返回标准通知结果。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        try {
            $payload = json_decode($request->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('易生回调不是合法 JSON', 40200);
        }
        if (!is_array($payload)
            || !is_array($payload['reqHeader'] ?? null)
            || !is_array($payload['reqBody'] ?? null)) {
            throw new PaymentException('易生回调结构无效', 40200);
        }

        $header = $payload['reqHeader'];
        $body = $payload['reqBody'];
        if (!$this->client()->verify($header, $body, (string) ($payload['reqSign'] ?? ''))) {
            throw new PaymentException('易生回调验签失败', 40200);
        }
        $this->assertNotifyProfile($header);
        $this->assertBusinessAccepted($body, '回调', false);

        $state = $this->requiredText($body['respStateInfo']['transState'] ?? '', '易生回调缺少 transState');
        $status = match ($state) {
            '0' => PaymentPluginStatusConstant::SUCCESS,
            '9' => PaymentPluginStatusConstant::PENDING,
            'X', 'A', 'C', 'R', 'E' => PaymentPluginStatusConstant::FAILED,
            default => throw new PaymentException('易生回调状态未知：' . $state, 40200),
        };

        $orderInfo = $this->responseOrderInfo($body, '回调');
        $payNo = $this->requiredText($orderInfo['orgTrace'] ?? '', '易生回调缺少商户订单号');
        $channelTradeNo = $this->requiredText($orderInfo['outTrace'] ?? '', '易生回调缺少 outTrace');
        $amount = $this->integerCents($orderInfo['transAmount'] ?? null, '易生回调金额');
        $notificationMchtCode = $this->requiredText($orderInfo['mchtCode'] ?? '', '易生回调缺少商户号');
        $this->assertSameText($this->mchtCode(), $notificationMchtCode, '易生回调商户号不属于当前配置');

        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('易生回调未匹配到本地支付单', 40200);
        }
        $this->assertNotifyOrder($payOrder, $amount, $channelTradeNo, $notificationMchtCode);

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'message' => $this->stateMessage($body),
            'chan_order_no' => $payNo,
            'chan_trade_no' => $channelTradeNo,
        ];
    }

    /**
     * 返回渠道要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '{"code":"000000","msg":"Success"}';
    }

    /**
     * 返回渠道要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '{"code":"100001","msg":"Fail"}';
    }

    /**
     * 构建当前订单可用的支付处理器。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, array<string, mixed>> 支付场景与产品处理器映射
     */
    private function paymentHandlers(array $order): array
    {
        return [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALI_PAY_JSAPI,
                    'wxpay' => self::PRODUCT_WE_CHAT_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'jump' => [
                'products' => ['bank' => self::PRODUCT_UNION_PAY_JSAPI],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALI_PAY_NATIVE,
                    'wxpay' => self::PRODUCT_WE_CHAT_NATIVE,
                    'bank' => self::PRODUCT_UNION_PAY_NATIVE,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ];
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPay(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        $product = match ($payType) {
            'alipay' => self::PRODUCT_ALI_PAY_NATIVE,
            'wxpay' => self::PRODUCT_WE_CHAT_NATIVE,
            'bank' => self::PRODUCT_UNION_PAY_NATIVE,
            default => throw new PaymentDefinitiveException('易生主扫支付方式无效', 40200),
        };
        $transDate = date('Ymd');
        $data = $this->execute('/trade/native', $this->tradePayload($order, $product, $transDate), '主扫下单');
        $this->assertTradeResponse($data, $order, '主扫下单');

        $qrcode = $this->requiredText($data['respOrderInfo']['qrCode'] ?? '', '易生主扫未返回 qrCode');

        return $this->payResult($order, $product, 'qrcode', 'trade.native', ['qrcode' => $qrcode], $data, $transDate);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        $payment = $this->paymentPayload($order);
        $product = match ($payType) {
            'alipay' => self::PRODUCT_ALI_PAY_JSAPI,
            'wxpay' => self::PRODUCT_WE_CHAT_JSAPI,
            'bank' => self::PRODUCT_UNION_PAY_JSAPI,
            default => throw new PaymentDefinitiveException('易生 JSAPI 支付方式无效', 40200),
        };
        $transDate = date('Ymd');
        $payload = $this->tradePayload($order, $product, $transDate);

        if ($payType === 'alipay') {
            $payload['aliBizParam'] = [
                'buyerId' => $this->requiredText($payment['buyer_id'] ?? '', '易生支付宝 JSAPI 缺少 buyer_id'),
            ];
        } elseif ($payType === 'wxpay') {
            $payload['wxBizParam'] = [
                'subAppid' => $this->requiredText($this->configText('wx_mp_app_id'), '易生微信 JSAPI 缺少公众号 AppID'),
                'subOpenId' => $this->requiredText($payment['sub_openid'] ?? '', '易生微信 JSAPI 缺少 sub_openid'),
            ];
        } else {
            $payload['qrBizParam'] = $this->unionpayBizParam($payment);
        }

        $data = $this->execute('/trade/jsapi', $payload, 'JSAPI下单');
        $state = $this->assertTradeResponse($data, $order, 'JSAPI下单');
        if ($payType === 'alipay') {
            $tradeNo = $this->requiredText($data['aliRespParamInfo']['tradeNo'] ?? '', '易生支付宝 JSAPI 未返回 tradeNo');
            return $this->payResult($order, $product, 'jsapi', 'trade.jsapi', ['tradeNO' => $tradeNo], $data, $transDate);
        }
        if ($payType === 'wxpay') {
            $params = $this->jsonObject(
                $data['wxRespParamInfo']['wcPayData'] ?? '',
                '易生微信 JSAPI wcPayData'
            );
            return $this->payResult($order, $product, 'jsapi', 'trade.jsapi', $params, $data, $transDate);
        }

        $redirectUrl = trim((string) ($data['qrRespParamInfo']['qrRedirectUrl'] ?? ''));
        if ($redirectUrl !== '') {
            return $this->payResult($order, $product, 'jump', 'trade.jsapi', ['url' => $redirectUrl], $data, $transDate);
        }
        if ($state === '0') {
            // 下单链路保持 pending，由原严格查单或回调确认资金；不返回收银台内部 ok 模式。
            return $this->payResult($order, $product, 'page', 'trade.jsapi', [
                '_page' => 'paymentPending',
                'description' => '支付请求已返回，请等待支付结果确认。',
            ], $data, $transDate);
        }

        throw new PaymentUncertainException('易生银联 JSAPI 已受理但未返回可承接结果', 40200);
    }

    /**
     * 构建交易请求参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 易生支付产品编码
     * @param string $transDate 交易日期，格式 Ymd
     * @return array<string, mixed> 渠道交易请求参数
     */
    private function tradePayload(array $order, string $product, string $transDate): array
    {
        return [
            'reqInfo' => ['mchtCode' => $this->mchtCode()],
            'reqOrderInfo' => [
                'orgTrace' => $this->requiredText($order['pay_no'] ?? '', '易生下单缺少 pay_no'),
                'transAmount' => $this->positiveCents($order['amount'] ?? 0, '易生下单金额无效'),
                'orderSub' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
                'backUrl' => $this->requiredText($order['callback_url'] ?? '', '易生下单缺少异步通知地址'),
            ],
            'payInfo' => ['payType' => $product, 'transDate' => $transDate],
        ];
    }

    /**
     * 构建银联业务参数。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @return array<string, mixed> 银联业务参数
     */
    private function unionpayBizParam(array $payment): array
    {
        $authCode = $this->requiredText($payment['unionpay_auth_code'] ?? '', '易生银联 JSAPI 缺少 unionpay_auth_code');
        $userId = trim((string) ($payment['unionpay_user_id'] ?? ''));
        if ($userId === '') {
            $userId = $this->unionpayUserId($authCode);
        }
        $qrCodeType = $this->requiredText($payment['unionpay_qr_code_type'] ?? '', '易生银联 JSAPI 缺少 unionpay_qr_code_type');
        if (!in_array($qrCodeType, ['0', '1'], true)) {
            throw new PaymentDefinitiveException('易生银联 JSAPI qrCodeType 必须为 0 或 1', 40200);
        }
        $areaInfo = $this->requiredText($payment['unionpay_area_info'] ?? '', '易生银联 JSAPI 缺少 unionpay_area_info');
        if (preg_match('/^\d{7}$/D', $areaInfo) !== 1) {
            throw new PaymentDefinitiveException('易生银联 JSAPI areaInfo 必须为 7 位数字', 40200);
        }
        $transType = $this->requiredText($payment['unionpay_trans_type'] ?? '', '易生银联 JSAPI 缺少 unionpay_trans_type');
        if (preg_match('/^[A-Za-z0-9]{2}$/D', $transType) !== 1) {
            throw new PaymentDefinitiveException('易生银联 JSAPI transType 必须为 2 位协议值', 40200);
        }
        $validTime = $this->positiveCents(
            $payment['unionpay_payment_valid_time'] ?? 0,
            '易生银联 JSAPI paymentValidTime 无效'
        );

        return [
            'userAuthCode' => $authCode,
            'userId' => $userId,
            'qrCode' => $this->requiredText($payment['unionpay_qr_code'] ?? '', '易生银联 JSAPI 缺少 unionpay_qr_code'),
            'qrCodeType' => $qrCodeType,
            'paymentValidTime' => $validTime,
            'transType' => $transType,
            'areaInfo' => $areaInfo,
        ];
    }

    /**
     * 使用银联授权码换取渠道用户标识。
     *
     * @param string $authCode 银联 userAuth 授权码
     * @return string 银联用户标识
     */
    private function unionpayUserId(string $authCode): string
    {
        $appUpIdentifier = $this->requiredText(
            $this->configText('unionpay_app_up_identifier'),
            '易生银联用户标识换取缺少 appUpIdentifier 配置'
        );
        $data = $this->execute('/trade/user/getQrUserId', [
            'reqInfo' => ['mchtCode' => $this->mchtCode()],
            'reqOrderInfo' => [
                'orgTrace' => $this->operationTrace('U'),
                'appUpIdentifier' => $appUpIdentifier,
                'authCode' => $authCode,
            ],
        ], '银联用户标识换取');
        $this->assertBusinessAccepted($data, '银联用户标识换取');

        return $this->requiredText($data['respOrderInfo']['userId'] ?? '', '易生银联用户标识接口未返回 userId');
    }

    /**
     * 校验交易响应。
     *
     * @param array<string, mixed> $data 易生交易响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $scene 业务场景说明
     * @return string 易生交易状态
     */
    private function assertTradeResponse(array $data, array $order, string $scene): string
    {
        $this->assertBusinessAccepted($data, $scene);
        $state = $this->requiredText($data['respStateInfo']['transState'] ?? '', '易生' . $scene . '缺少 transState');
        if (in_array($state, self::PAYMENT_FAILURE_STATES, true)) {
            throw new PaymentDefinitiveException('易生' . $scene . '失败：' . $this->stateMessage($data), 40200);
        }
        if (!in_array($state, ['0', '9'], true)) {
            throw new PaymentUncertainException('易生' . $scene . '状态未知：' . $state, 40200);
        }

        $orderInfo = $this->responseOrderInfo($data, $scene);
        $payNo = $this->requiredText($order['pay_no'] ?? '', '易生' . $scene . '缺少 pay_no');
        $this->assertSameText($payNo, $orderInfo['orgTrace'] ?? '', '易生' . $scene . '商户订单号不一致', true);
        $amount = $this->integerCents($orderInfo['transAmount'] ?? null, '易生' . $scene . '响应金额');
        if ($amount !== (int) ($order['amount'] ?? 0)) {
            throw new PaymentUncertainException('易生' . $scene . '金额不一致', 40200);
        }
        $this->requiredText($orderInfo['outTrace'] ?? '', '易生' . $scene . '响应缺少 outTrace');

        return $state;
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 易生支付产品编码
     * @param string $page 平台承接页类型
     * @param string $action 易生接口动作
     * @param array<string, mixed> $payParams 前端调起参数
     * @param array<string, mixed> $data 易生下单响应
     * @param string $transDate 交易日期，格式 Ymd
     * @return array<string, mixed> 标准支付结果
     */
    private function payResult(
        array $order,
        string $product,
        string $page,
        string $action,
        array $payParams,
        array $data,
        string $transDate
    ): array {
        $payNo = (string) $order['pay_no'];
        $outTrace = (string) $data['respOrderInfo']['outTrace'];

        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $outTrace,
            'channel_context' => [
                'trans_date' => $transDate,
                'req_id' => $this->reqId(),
                'req_type' => $this->reqType(),
                'mcht_code' => $this->mchtCode(),
                'pay_product' => $product,
                'out_trace' => $outTrace,
            ],
        ]);
    }

    /**
     * 声明支付宝支付所需的用户身份。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 支付宝身份要求；已有 buyer_id 时返回 null
     */
    private function alipayIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['buyer_id'] ?? '')) !== '') {
            return null;
        }
        foreach (['alipay_oauth_app_id', 'alipay_oauth_private_key_path', 'alipay_oauth_public_key_path'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentDefinitiveException('易生支付宝 JSAPI 缺少 buyer_id，且未配置完整生活号授权参数', 40200);
            }
        }

        return [
            'provider' => 'alipay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_ALI_PAY_JSAPI,
            'auth_type' => 'alipay_oauth',
            'identity_field' => 'buyer_id',
            'identity_aliases' => [],
            'app_id' => $this->configText('alipay_oauth_app_id'),
            'scope' => 'auth_base',
            '_alipay_config' => [
                'mode' => 'key',
                'app_id' => $this->configText('alipay_oauth_app_id'),
                'private_key' => $this->privateAssetContents(
                    $this->configText('alipay_oauth_private_key_path'),
                    ['key', 'pem'],
                    '支付宝授权应用私钥'
                ),
                'alipay_public_key' => $this->privateAssetContents(
                    $this->configText('alipay_oauth_public_key_path'),
                    ['cer', 'crt', 'pem'],
                    '支付宝公钥'
                ),
                'sandbox' => $this->configBool('sandbox'),
            ],
            'message' => '易生支付宝 JSAPI 需要先取得 buyer_id',
        ];
    }

    /**
     * 声明微信支付所需的用户身份。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 微信身份要求；已有 sub_openid 时返回 null
     */
    private function wechatIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['sub_openid'] ?? '')) !== '') {
            return null;
        }
        $appId = $this->configText('wx_mp_app_id');
        $appSecret = $this->configText('wx_mp_app_secret');
        if ($appId === '' || $appSecret === '') {
            throw new PaymentDefinitiveException('易生微信 JSAPI 缺少 sub_openid，且未配置公众号 AppID/AppSecret', 40200);
        }

        return [
            'provider' => 'wxpay',
            'product' => 'mp',
            'channel_product' => self::PRODUCT_WE_CHAT_JSAPI,
            'auth_type' => 'wechat_oauth',
            'identity_field' => 'sub_openid',
            'identity_aliases' => ['openid'],
            'app_id' => $appId,
            '_app_secret' => $appSecret,
            'scope' => 'snsapi_base',
            'message' => '易生微信公众号支付需要先取得 sub_openid',
        ];
    }

    /**
     * 声明银联支付所需的用户身份。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 银联身份要求；已有授权码时返回 null
     */
    private function unionpayIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['unionpay_auth_code'] ?? '')) !== '') {
            return null;
        }

        return [
            'provider' => 'unionpay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_UNION_PAY_JSAPI,
            'auth_type' => 'unionpay_user_auth',
            'identity_field' => 'unionpay_auth_code',
            'identity_aliases' => [],
            'app_id' => '',
            'message' => '易生银联 JSAPI 需要先完成银联 userAuth 授权',
        ];
    }

    /**
     * 校验通知接口档案。
     *
     * @param array<string, mixed> $header 易生通知头
     * @return void
     */
    private function assertNotifyProfile(array $header): void
    {
        $this->assertSameText($this->reqType(), $header['reqType'] ?? '', '易生回调 reqType 与当前 profile 不一致');
        $this->assertSameText($this->reqId(), $header['reqId'] ?? '', '易生回调 reqId 与当前 profile 不一致');
    }

    /**
     * 校验通知与本地支付单及下单上下文一致。
     *
     * @param PayOrder $payOrder 本地支付单
     * @param int $amount 通知金额，单位分
     * @param string $channelTradeNo 通知渠道流水号
     * @param string $notificationMchtCode 通知商户号
     * @return void
     */
    private function assertNotifyOrder(
        PayOrder $payOrder,
        int $amount,
        string $channelTradeNo,
        string $notificationMchtCode
    ): void {
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('易生回调支付单不属于当前通道', 40200);
        }
        if ((int) $payOrder->pay_amount !== $amount) {
            throw new PaymentException('易生回调金额与本地支付单不一致', 40200);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $channelTradeNo)) {
            throw new PaymentException('易生回调 outTrace 与本地支付单不一致', 40200);
        }
        $storedOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedOrderNo !== '' && !hash_equals((string) $payOrder->pay_no, $storedOrderNo)) {
            throw new PaymentException('易生本地支付单渠道订单号上下文异常', 40200);
        }

        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $channelContext = (array) ($context['channel_context'] ?? []);
        $product = trim((string) ($context['pay_product'] ?? ''));
        if (!in_array($product, self::PRODUCTS, true)
            || !hash_equals($product, trim((string) ($channelContext['pay_product'] ?? '')))) {
            throw new PaymentException('易生回调支付产品上下文不一致', 40200);
        }
        foreach ([
            'req_id' => $this->reqId(),
            'req_type' => $this->reqType(),
            'mcht_code' => $notificationMchtCode,
        ] as $field => $expected) {
            $actual = trim((string) ($channelContext[$field] ?? ''));
            if ($actual === '' || !hash_equals($expected, $actual)) {
                throw new PaymentException('易生回调 ' . $field . ' 与下单上下文不一致', 40200);
            }
        }
    }

    /**
     * 校验上游业务响应是否已受理。
     *
     * @param array<string, mixed> $data 易生响应
     * @param string $scene 业务场景说明
     * @param bool $uncertainAllowed 是否按响应码区分结果不确定异常
     * @return void
     */
    private function assertBusinessAccepted(array $data, string $scene, bool $uncertainAllowed = true): void
    {
        $responseCode = trim((string) ($data['respStateInfo']['respCode'] ?? ''));
        if ($responseCode === '000000') {
            return;
        }
        if ($responseCode === '') {
            $exception = $uncertainAllowed ? PaymentUncertainException::class : PaymentException::class;
            throw new $exception('易生' . $scene . '缺少业务 respCode', 40200);
        }
        $message = '易生' . $scene . '失败：[' . $responseCode . ']'
            . trim((string) ($data['respStateInfo']['respDesc'] ?? ''));
        if ($uncertainAllowed && ($responseCode === 'QT0099'
            || $responseCode === '999999'
            || str_starts_with($responseCode, 'QTC0'))) {
            throw new PaymentUncertainException($message, 40200);
        }

        throw new PaymentDefinitiveException($message, 40200);
    }

    /**
     * 读取响应订单信息。
     *
     * @param array<string, mixed> $data 易生响应
     * @param string $scene 业务场景说明
     * @return array<string, mixed> 响应订单信息
     */
    private function responseOrderInfo(array $data, string $scene): array
    {
        $orderInfo = $data['respOrderInfo'] ?? null;
        if (!is_array($orderInfo)) {
            throw new PaymentUncertainException('易生' . $scene . '响应缺少 respOrderInfo', 40200);
        }

        return $orderInfo;
    }

    /**
     * 获取渠道状态说明。
     *
     * @param array<string, mixed> $data 易生响应
     * @return string 渠道状态说明
     */
    private function stateMessage(array $data): string
    {
        return trim((string) ($data['respStateInfo']['transStatusDesc']
            ?? $data['respStateInfo']['appendRetMsg']
            ?? $data['respStateInfo']['respDesc']
            ?? ''));
    }

    /**
     * 读取原交易日期。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @param string $scene 业务场景说明
     * @return string 原交易日期，格式 Ymd
     */
    private function originalTransDate(array $order, string $scene): string
    {
        $channelContext = (array) ($order['channel_context'] ?? []);
        $transDate = trim((string) ($channelContext['trans_date'] ?? ''));
        if (preg_match('/^\d{8}$/D', $transDate) !== 1) {
            throw new PaymentDefinitiveException('易生' . $scene . '缺少原交易 trans_date 上下文', 40200);
        }

        return $transDate;
    }

    /**
     * 读取支付单的实际产品。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @param string $scene 业务场景说明
     * @return string 下单产品快照
     */
    private function orderProduct(array $order, string $scene): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if (!in_array($product, self::PRODUCTS, true)) {
            throw new PaymentDefinitiveException('易生' . $scene . '缺少确定支付产品上下文', 40200);
        }

        return $product;
    }

    /**
     * 执行上游 SDK 请求。
     *
     * SDK 标记为结果不确定的传输或协议异常必须保留该语义，不能降级为普通失败。
     *
     * @param string $path 易生接口路径
     * @param array<string, mixed> $body 请求体
     * @param string $scene 业务场景说明
     * @return array<string, mixed> 易生响应
     */
    private function execute(string $path, array $body, string $scene): array
    {
        try {
            return $this->client()->execute($path, $body);
        } catch (EasypaySdkException $e) {
            if ($e->isUncertain()) {
                throw new PaymentUncertainException('易生' . $scene . '结果不确定：' . $e->getMessage(), 40200);
            }
            throw new PaymentDefinitiveException('易生' . $scene . '失败：' . $e->getMessage(), 40200);
        }
    }

    /**
     * 获取当前通道的易生客户端。
     *
     * 客户端使用私有证书对象键加载密钥材料，初始化失败属于请求发出前的确定失败。
     *
     * @return EasypayClient
     */
    private function client(): EasypayClient
    {
        if ($this->client === null) {
            try {
                $this->client = new EasypayClient([
                    'req_id' => $this->reqId(),
                    'req_type' => $this->reqType(),
                    'certificate_id' => $this->configText('certificate_id'),
                    'platform_public_key' => $this->privateAssetContents(
                        $this->configText('platform_public_key_path'),
                        ['cer', 'crt', 'pem'],
                        '易生公钥/公钥证书'
                    ),
                    'merchant_private_key' => $this->privateAssetContents(
                        $this->configText('merchant_private_key_path'),
                        ['key', 'pem'],
                        '易生商户 RSA 私钥'
                    ),
                    'sandbox' => $this->configBool('sandbox'),
                ]);
            } catch (EasypaySdkException $e) {
                throw new PaymentDefinitiveException('易生 SDK 初始化失败：' . $e->getMessage(), 40200);
            }
        }

        return $this->client;
    }

    /**
     * 校验通道接入档案、产品和私有证书配置。
     *
     * @return void
     */
    private function validateConfiguration(): void
    {
        $this->reqType();
        $this->reqId();
        $this->mchtCode();
        $this->assertPrivateObjectKeySyntax(
            $this->configText('platform_public_key_path'),
            ['cer', 'crt', 'pem'],
            '易生公钥/公钥证书'
        );
        $this->assertPrivateObjectKeySyntax(
            $this->configText('merchant_private_key_path'),
            ['key', 'pem'],
            '易生商户 RSA 私钥'
        );
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, self::PRODUCTS) !== []) {
            throw new PaymentDefinitiveException('易生已开通产品为空或包含未知产品', 40200);
        }
        if (in_array(self::PRODUCT_WE_CHAT_JSAPI, $enabled, true)
            && ($this->configText('wx_mp_app_id') === '' || $this->configText('wx_mp_app_secret') === '')) {
            throw new PaymentDefinitiveException('易生微信公众号 JSAPI 必须配置 AppID/AppSecret', 40200);
        }
        if (in_array(self::PRODUCT_UNION_PAY_JSAPI, $enabled, true)
            && $this->configText('unionpay_app_up_identifier') === '') {
            throw new PaymentDefinitiveException('易生银联 JSAPI 必须配置 appUpIdentifier', 40200);
        }

        $alipayOAuthFields = [
            'alipay_oauth_app_id',
            'alipay_oauth_private_key_path',
            'alipay_oauth_public_key_path',
        ];
        $configuredOAuthFields = array_filter(
            $alipayOAuthFields,
            fn (string $field): bool => $this->configText($field) !== ''
        );
        if ($configuredOAuthFields !== [] && count($configuredOAuthFields) !== count($alipayOAuthFields)) {
            throw new PaymentDefinitiveException('易生支付宝生活号授权配置必须完整', 40200);
        }
        if ($configuredOAuthFields !== []) {
            $this->assertPrivateObjectKeySyntax(
                $this->configText('alipay_oauth_private_key_path'),
                ['key', 'pem'],
                '支付宝授权应用私钥'
            );
            $this->assertPrivateObjectKeySyntax(
                $this->configText('alipay_oauth_public_key_path'),
                ['cer', 'crt', 'pem'],
                '支付宝公钥'
            );
        }
    }

    /**
     * 读取已开通支付产品。
     *
     * @return array<int, string>
     */
    private function enabledProducts(): array
    {
        $products = $this->getConfig('enabled_products', []);
        if (is_string($products)) {
            $decoded = json_decode($products, true);
            $products = is_array($decoded) ? $decoded : explode(',', $products);
        }

        return is_array($products)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $product): string => trim((string) $product),
                $products
            ))))
            : [];
    }

    /**
     * 读取并校验易生接入模式。
     *
     * @return string 1 表示商户模式，2 表示机构模式
     */
    private function reqType(): string
    {
        $reqType = $this->configText('req_type');
        if (!in_array($reqType, ['1', '2'], true)) {
            throw new PaymentDefinitiveException('易生 req_type 必须显式配置为 1 或 2', 40200);
        }

        return $reqType;
    }

    private function reqId(): string
    {
        return $this->requiredText($this->configText('req_id'), '易生 req_id 不能为空');
    }

    /**
     * 解析当前交易使用的易生商户号。
     *
     * 商户模式直接使用 req_id，机构模式必须使用明确配置的子商户号。
     *
     * @return string 易生交易商户号
     */
    private function mchtCode(): string
    {
        if ($this->reqType() === '1') {
            return $this->reqId();
        }

        return $this->requiredText(
            $this->configText('sub_merchant_no'),
            '易生机构模式必须显式配置子商户号'
        );
    }

    /**
     * 读取标准支付载体参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        return (array) (($order['extra']['payment'] ?? null) ?? []);
    }

    private function operationTrace(string $prefix): string
    {
        return $prefix . date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return $text;
    }

    private function positiveCents(mixed $value, string $message): int
    {
        $text = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/D', $text) !== 1) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return (int) $text;
    }

    private function integerCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+$/D', $text) !== 1) {
            throw new PaymentUncertainException($field . '格式无效', 40200);
        }

        return (int) $text;
    }

    /**
     * 使用恒定时间比较校验协议文本一致。
     *
     * @param string $expected 预期值
     * @param mixed $actual 实际值
     * @param string $message 校验失败消息
     * @param bool $uncertain 不一致时是否视为结果不确定
     * @return void
     */
    private function assertSameText(
        string $expected,
        mixed $actual,
        string $message,
        bool $uncertain = false
    ): void {
        $actualText = trim((string) $actual);
        if ($actualText === '' || !hash_equals($expected, $actualText)) {
            $exception = $uncertain ? PaymentUncertainException::class : PaymentException::class;
            throw new $exception($message, 40200);
        }
    }

    /**
     * 将 JSON 对象解码为关联数组。
     *
     * @param mixed $value JSON 文本
     * @param string $field 字段说明
     * @return array<string, mixed> JSON 对象
     */
    private function jsonObject(mixed $value, string $field): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new PaymentUncertainException($field . '为空', 40200);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentUncertainException($field . '不是合法 JSON', 40200);
        }
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new PaymentUncertainException($field . '结构无效', 40200);
        }

        return $decoded;
    }

    /**
     * 构建输入框配置项。
     *
     * @param string $field 配置字段名
     * @param string $title 配置项标题
     * @param bool $required 是否必填
     * @return array<string, mixed> 输入框配置
     */
    private function inputField(string $field, string $title, bool $required = false): array
    {
        $schema = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => ''];
        if ($required) {
            $schema['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $schema;
    }

    /**
     * `WeChatNative` 未在当前公开产品清单中声明，只有商户确认已开通时才允许手工勾选。
     *
     * @param array<string, string> $options 产品选项
     * @return array<string, mixed> 产品开通配置
     */
    private function enabledProductsField(array $options): array
    {
        $field = $this->directPaymentEnabledProductsField($options);
        $field['value'] = array_values(array_diff(array_keys($options), [self::PRODUCT_WE_CHAT_NATIVE]));

        return $field;
    }

    /**
     * 构建私有文件上传配置。
     *
     * @param string $accept 允许上传的扩展名
     * @return array<string, mixed> 私有文件上传配置
     */
    private function uploadProps(string $accept): array
    {
        return [
            'fileUpload' => [
                'scene' => FileConstant::SCENE_CERTIFICATE,
                'visibility' => FileConstant::VISIBILITY_PRIVATE,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'object_key',
                'accept' => $accept,
                'limit' => 1,
                'multiple' => false,
                'showFileList' => true,
            ],
        ];
    }

    /**
     * 校验私有文件对象键格式。
     *
     * @param string $objectKey 私有证书对象键
     * @param array<int, string> $extensions 允许的扩展名
     * @param string $label 文件说明
     * @return void
     */
    private function assertPrivateObjectKeySyntax(string $objectKey, array $extensions, string $label): void
    {
        $normalized = str_replace('\\', '/', trim($objectKey));
        $segments = explode('/', $normalized);
        if ($normalized === ''
            || str_contains($normalized, "\0")
            || str_contains($normalized, '://')
            || preg_match('#^storage/private/certificate/[A-Za-z0-9][A-Za-z0-9_./-]*$#D', $normalized) !== 1
            || array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []
            || !in_array(strtolower(pathinfo($normalized, PATHINFO_EXTENSION)), $extensions, true)) {
            throw new PaymentDefinitiveException($label . '必须使用 MPAY 私有证书文件 object_key', 40200);
        }
    }

    /**
     * 读取私有文件资产内容。
     *
     * 对象键通过语法校验后仍需使用 realpath 约束在私有证书目录内，防止路径穿越。
     *
     * @param string $objectKey 私有证书对象键
     * @param array<int, string> $extensions 允许的扩展名
     * @param string $label 文件说明
     * @return string 文件内容
     */
    private function privateAssetContents(string $objectKey, array $extensions, string $label): string
    {
        $this->assertPrivateObjectKeySyntax($objectKey, $extensions, $label);
        $normalized = str_replace('\\', '/', trim($objectKey));
        $root = realpath(runtime_path(FileConstant::LOCAL_PRIVATE_DIR . '/certificate'));
        $resolved = realpath(runtime_path($normalized));
        if (!is_string($root) || !is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
            throw new PaymentDefinitiveException($label . '文件不存在或不可读', 40200);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $resolvedNormalized = str_replace('\\', '/', $resolved);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $resolvedNormalized = strtolower($resolvedNormalized);
        }
        if (!str_starts_with($resolvedNormalized, $root)) {
            throw new PaymentDefinitiveException($label . ' object_key 越出 MPAY 私有证书目录', 40200);
        }
        $contents = file_get_contents($resolved);
        if (!is_string($contents) || trim($contents) === '') {
            throw new PaymentDefinitiveException($label . '文件为空', 40200);
        }

        return $contents;
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    private function configBool(string $key): bool
    {
        return filter_var($this->getConfig($key, false), FILTER_VALIDATE_BOOL);
    }
}
