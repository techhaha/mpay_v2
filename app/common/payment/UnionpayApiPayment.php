<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\unionpay\UnionpayClient;
use app\common\sdk\unionpay\UnionpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use JsonException;
use support\Request;
use support\Response;

/**
 * 中国银联条码支付综合前置平台 API 插件。
 *
 * 该插件适配名为“银联前置”的 Swiftpass XML 合同，不是银联 ACP，
 * 也不与威富通插件共享网关、凭据或未经商户文档确认的协议实现。
 */
class UnionpayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_BANK_JSAPI = 'bank_jsapi';
    private const PRODUCT_WXPAY_H5 = 'wxpay_h5';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_QQPAY_SCAN = 'qqpay_scan';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private const SERVICE_NATIVE = 'unified.trade.native';
    private const SERVICE_WXPAY_JSAPI = 'pay.weixin.jspay';
    private const SERVICE_ALIPAY_JSAPI = 'pay.alipay.jspay';
    private const SERVICE_UNIONPAY_USER_ID = 'pay.unionpay.userid';
    private const SERVICE_UNIONPAY_JSAPI = 'pay.unionpay.jspay';
    private const SERVICE_WXPAY_H5 = 'pay.weixin.wappay';
    private const SERVICE_REFUND = 'unified.trade.refund';

    private const NOTIFY_SUCCESS_STATES = ['SUCCESS'];
    private const NOTIFY_PENDING_STATES = ['NOTPAY', 'USERPAYING', 'PROCESSING', 'ACCEPTED'];
    private const NOTIFY_FAILED_STATES = ['CLOSED', 'REVOKED', 'PAYERROR', 'FAILED'];

    private ?UnionpayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'unionpay_api',
        'name' => '银联前置支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.95516.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'qqpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造银联前置支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付通知归属校验所用仓储；为空时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
    }

    /**
     * 初始化银联前置通道配置。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;
    }

    /**
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        $products = $this->directPaymentEnabledProductsField([
            self::PRODUCT_WXPAY_MP => '微信公众号 JSAPI（rainbow_legacy）',
            self::PRODUCT_WXPAY_MINI => '微信小程序（rainbow_legacy）',
            self::PRODUCT_ALIPAY_JSAPI => '支付宝服务窗（rainbow_legacy）',
            self::PRODUCT_BANK_JSAPI => '银联 JSAPI（rainbow_legacy）',
            self::PRODUCT_WXPAY_H5 => '微信 H5（rainbow_legacy）',
            self::PRODUCT_WXPAY_SCAN => '微信统一主扫（rainbow_legacy）',
            self::PRODUCT_ALIPAY_SCAN => '支付宝统一主扫（rainbow_legacy）',
            self::PRODUCT_QQPAY_SCAN => 'QQ 统一主扫（rainbow_legacy）',
            self::PRODUCT_BANK_SCAN => '银联统一主扫（rainbow_legacy）',
        ]);
        // 没有真实商户闭环时不默认开通任何产品，避免刷新配置后扩大生产能力。
        $products['value'] = [];

        return [
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'input', 'field' => 'sub_mch_id', 'title' => '子商户号', 'value' => ''],
            ['type' => 'password', 'field' => 'key', 'title' => '商户 MD5 密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户密钥不能为空']]],
            ['type' => 'input', 'field' => 'gateway_url', 'title' => '自定义 HTTPS 网关', 'value' => '', 'props' => ['placeholder' => '留空使用 https://qra.95516.com/pay/gateway']],
            ['type' => 'input', 'field' => 'wx_mp_app_id', 'title' => '微信公众号 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wx_mini_app_id', 'title' => '微信小程序 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'wx_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wx_mini_launch_path', 'title' => '微信小程序支付页路径', 'value' => 'pages/pay/index'],
            ['type' => 'input', 'field' => 'alipay_oauth_app_id', 'title' => '支付宝授权应用 AppID', 'value' => ''],
            ['type' => 'textarea', 'field' => 'alipay_oauth_private_key', 'title' => '支付宝授权应用私钥', 'value' => ''],
            ['type' => 'textarea', 'field' => 'alipay_oauth_public_key', 'title' => '支付宝公钥', 'value' => ''],
            ['type' => 'input', 'field' => 'unionpay_app_up_identifier', 'title' => '银联 appUpIdentifier', 'value' => ''],
            $products,
        ];
    }

    /**
     * 发起支付。
     *
     * 微信小程序和银联 JSAPI 需要先按标准身份字段确定产品，不能依赖公共候选器
     * 把 `mini_openid`、银联授权码或其它平台身份当成同一类 JSAPI 参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        if ($this->isWechatMiniOrder($order)) {
            $this->ensureProduct(self::PRODUCT_WXPAY_MINI);

            return $this->wechatJsapiPay($order, true);
        }
        if ($this->isBankJsapiOrder($order)) {
            $this->ensureProduct(self::PRODUCT_BANK_JSAPI);

            return $this->unionpayJsapiPay($order);
        }

        $payType = (string) ($order['pay_type_code'] ?? '');

        return $this->executeDirectPaymentProduct($order, [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WXPAY_MP,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                    ? $this->wechatJsapiPay($order, false)
                    : $this->alipayJsapiPay($order),
            ],
            'h5' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => fn (): array => $this->wechatH5Pay($order),
            ],
            'jump' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => fn (): array => $this->wechatH5Pay($order),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'qqpay' => self::PRODUCT_QQPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                ],
                'handler' => fn (): array => $this->nativePay($order, $payType),
            ],
        ], '银联前置');
    }

    /**
     * 声明当前产品需要的第三方用户身份。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>|null 身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        if ($this->isWechatMiniOrder($order)) {
            $this->ensureProduct(self::PRODUCT_WXPAY_MINI);

            return $this->wechatIdentityRequirement($order, true);
        }
        if ($this->isBankJsapiOrder($order)) {
            $this->ensureProduct(self::PRODUCT_BANK_JSAPI);

            return $this->unionpayIdentityRequirement($order);
        }

        $handlers = $this->directPaymentUsableHandlers($order, [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WXPAY_MP,
                ],
                'handler' => static fn (): array => [],
            ],
            'h5' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => static fn (): array => [],
            ],
            'jump' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => static fn (): array => [],
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'qqpay' => self::PRODUCT_QQPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                ],
                'handler' => static fn (): array => [],
            ],
        ]);
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));
        if (($candidates[0] ?? '') !== 'jsapi') {
            return null;
        }

        return match ((string) ($order['pay_type_code'] ?? '')) {
            'wxpay' => $this->wechatIdentityRequirement($order, false),
            'alipay' => $this->alipayIdentityRequirement($order),
            default => null,
        };
    }

    /**
     * 当前适配合同没有可确认的主动查单字段和状态闭环。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银联前置插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配合同没有可确认的关单字段和状态闭环。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     *
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银联前置插件暂不支持关单', 40200);
    }

    /**
     * 申请退款并区分受理、成功和未知状态。
     *
     * 原渠道交易号优先于商户订单号；一次请求只发送一种原交易引用，避免上游
     * 对两个引用解释不一致。没有明确资金退款成功状态时只能返回 pending。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     *
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '银联前置退款缺少 pay_no');
        $refundNo = $this->requiredText($order['refund_no'] ?? '', '银联前置退款缺少 refund_no');
        $totalFee = $this->positiveCents($order['amount'] ?? null, '银联前置退款原订单金额');
        $refundFee = $this->positiveCents($order['refund_amount'] ?? null, '银联前置退款金额');
        if ($refundFee > $totalFee) {
            throw new PaymentDefinitiveException('银联前置退款金额不能大于原订单金额', 40200);
        }

        $transactionId = trim((string) ($order['chan_trade_no'] ?? ''));
        $outTradeNo = trim((string) ($order['chan_order_no'] ?? ''));
        if ($transactionId === '' && $outTradeNo === '') {
            throw new PaymentDefinitiveException('银联前置退款缺少明确的原交易引用', 40200);
        }

        $payload = [
            'service' => self::SERVICE_REFUND,
            'out_refund_no' => $refundNo,
            'total_fee' => (string) $totalFee,
            'refund_fee' => (string) $refundFee,
            'op_user_id' => $this->configText('mch_id'),
        ];
        if ($transactionId !== '') {
            $payload['transaction_id'] = $transactionId;
        } else {
            $payload['out_trade_no'] = $outTradeNo;
        }

        $data = $this->gatewayRequest($payload, '退款');
        $this->assertSameText($refundNo, $data['out_refund_no'] ?? '', '银联前置退款响应请求号不一致');
        $this->assertSameCents($totalFee, $data['total_fee'] ?? null, '银联前置退款响应原订单金额');
        $this->assertSameCents($refundFee, $data['refund_fee'] ?? null, '银联前置退款响应金额');
        if ($transactionId !== '') {
            $this->assertSameText($transactionId, $data['transaction_id'] ?? '', '银联前置退款响应交易号不一致');
        } else {
            $this->assertSameText($outTradeNo, $data['out_trade_no'] ?? '', '银联前置退款响应商户订单号不一致');
        }
        $refundId = $this->requiredResponseText($data['refund_id'] ?? '', '银联前置退款响应缺少 refund_id');
        $refundStatus = strtoupper(trim((string) ($data['refund_status'] ?? '')));
        $status = match ($refundStatus) {
            'SUCCESS', 'REFUND_SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            '', 'ACCEPTED', 'PROCESSING', 'PENDING' => PaymentPluginStatusConstant::PENDING,
            'NOTSURE', 'UNKNOWN' => PaymentPluginStatusConstant::UNKNOWN,
            'FAIL', 'FAILED', 'CHANGE' => throw new PaymentDefinitiveException('银联前置退款被渠道明确拒绝', 40200),
            default => throw new PaymentUncertainException('银联前置退款返回未识别状态', 40200),
        };

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundFee,
            'chan_refund_no' => $refundId,
            'channel_status' => $refundStatus === '' ? 'ACCEPTED' : $refundStatus,
            'message' => match ($status) {
                PaymentPluginStatusConstant::SUCCESS => '退款成功',
                PaymentPluginStatusConstant::PENDING => '退款已受理',
                default => '退款状态未知',
            },
        ];
    }

    /**
     * 验签并归一化支付通知。
     *
     * 通信状态、业务状态和交易状态分层判断，并按本地支付单核对通道、金额及已保存的渠道编号。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        try {
            $payload = $this->client()->notify($request->rawBody());
        } catch (UnionpaySdkException $e) {
            throw new PaymentException($e->getMessage(), 40200);
        }

        $this->assertNotificationCommunicationSuccess($payload);
        $businessSuccess = $this->notificationBusinessSuccess($payload);
        $tradeState = strtoupper($this->requiredText($payload['trade_state'] ?? '', '银联前置回调缺少 trade_state'));
        $status = $businessSuccess
            ? match (true) {
                in_array($tradeState, self::NOTIFY_SUCCESS_STATES, true) => PaymentPluginStatusConstant::SUCCESS,
                in_array($tradeState, self::NOTIFY_PENDING_STATES, true) => PaymentPluginStatusConstant::PENDING,
                in_array($tradeState, self::NOTIFY_FAILED_STATES, true) => PaymentPluginStatusConstant::FAILED,
                default => throw new PaymentUncertainException('银联前置回调交易状态无法确认', 40200),
            }
            : PaymentPluginStatusConstant::FAILED;

        $this->assertResponseMerchant($payload, '回调');
        $payNo = $this->requiredText($payload['out_trade_no'] ?? '', '银联前置回调缺少 out_trade_no');
        $amount = $this->positiveCents($payload['total_fee'] ?? null, '银联前置回调金额');
        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));
        if ($status === PaymentPluginStatusConstant::SUCCESS && $transactionId === '') {
            throw new PaymentException('银联前置成功回调缺少 transaction_id', 40200);
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('银联前置回调未匹配到本地支付单', 40200);
        }
        $this->assertNotifyOrder($payOrder, $amount, $transactionId);

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'message' => $tradeState,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $transactionId,
            'channel_status' => $tradeState,
        ];
    }

    /**
     * 返回协议要求的成功 XML 应答。
     */
    public function notifySuccess(): string|Response
    {
        return '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
    }

    /**
     * 返回协议要求的失败 XML 应答。
     */
    public function notifyFail(): string|Response
    {
        return '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[FAIL]]></return_msg></xml>';
    }

    /**
     * 发起统一主扫支付。
     *
     * 当前合同中支付宝、微信、QQ 和银联扫码都调用 `unified.trade.native`；
     * `pay.*.native` 属于另一类 Swiftpass 合同，不能作为本插件的 service。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function nativePay(array $order, string $payType): array
    {
        $product = match ($payType) {
            'wxpay' => self::PRODUCT_WXPAY_SCAN,
            'qqpay' => self::PRODUCT_QQPAY_SCAN,
            'bank' => self::PRODUCT_BANK_SCAN,
            'alipay' => self::PRODUCT_ALIPAY_SCAN,
            default => throw new UnsupportedPaymentOperationException('银联前置不支持当前扫码支付方式', 40200),
        };
        $this->ensureProduct($product);

        $data = $this->gatewayRequest($this->basePayload($order) + [
            'service' => self::SERVICE_NATIVE,
        ], '统一主扫下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '统一主扫下单');
        $qrcode = $this->requiredResponseText($data['code_url'] ?? '', '银联前置统一主扫响应缺少 code_url');
        if ($payType === 'qqpay') {
            $qrcode = $this->qqQrcode($qrcode);
        }

        return $this->payResult('qrcode', $payType, $product, self::SERVICE_NATIVE, [
            'qrcode' => $qrcode,
        ], $data, $order);
    }

    /**
     * 发起微信公众号或小程序支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否微信小程序
     *
     * @return array<string, mixed> 标准 JSAPI 待支付结果
     */
    private function wechatJsapiPay(array $order, bool $mini): array
    {
        $product = $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP;
        $this->ensureProduct($product);
        $payment = $this->paymentPayload($order);
        $appId = $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        $openid = $mini
            ? trim((string) ($payment['mini_openid'] ?? ''))
            : $this->firstText($payment['openid'] ?? '', $payment['sub_openid'] ?? '');
        if ($appId === '' || $openid === '') {
            throw new PaymentDefinitiveException($mini
                ? '银联前置微信小程序缺少 mini_openid 或小程序 AppID'
                : '银联前置微信公众号缺少 openid 或公众号 AppID', 40200);
        }
        $requestAppId = trim((string) ($payment['sub_appid'] ?? ''));
        if ($requestAppId !== '' && !hash_equals($appId, $requestAppId)) {
            throw new PaymentDefinitiveException('银联前置微信身份 AppID 作用域不一致', 40200);
        }

        $data = $this->gatewayRequest($this->basePayload($order) + [
            'service' => self::SERVICE_WXPAY_JSAPI,
            'is_raw' => '1',
            'is_minipg' => $mini ? '1' : '0',
            'sub_appid' => $appId,
            'sub_openid' => $openid,
            'device_info' => 'AND_WAP',
        ], $mini ? '微信小程序下单' : '微信公众号下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '微信 JSAPI 下单');
        $payInfo = $this->jsonObject($data['pay_info'] ?? '', '银联前置微信 JSAPI pay_info');

        return $this->payResult('jsapi', 'wxpay', $product, self::SERVICE_WXPAY_JSAPI, $payInfo, $data, $order);
    }

    /**
     * 发起支付宝服务窗支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准支付宝 JSAPI 待支付结果
     */
    private function alipayJsapiPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_ALIPAY_JSAPI);
        $payment = $this->paymentPayload($order);
        $buyerId = trim((string) ($payment['buyer_id'] ?? ''));
        if ($buyerId === '') {
            throw new PaymentDefinitiveException('银联前置支付宝服务窗支付缺少 buyer_id', 40200);
        }

        $data = $this->gatewayRequest($this->basePayload($order) + [
            'service' => self::SERVICE_ALIPAY_JSAPI,
            'buyer_id' => $buyerId,
        ], '支付宝服务窗下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '支付宝服务窗下单');
        $payInfo = $this->jsonObject($data['pay_info'] ?? '', '银联前置支付宝 pay_info');
        $tradeNo = $this->requiredResponseText($payInfo['tradeNO'] ?? '', '银联前置支付宝 pay_info 缺少 tradeNO');

        return $this->payResult('jsapi', 'alipay', self::PRODUCT_ALIPAY_JSAPI, self::SERVICE_ALIPAY_JSAPI, [
            'tradeNO' => $tradeNo,
        ], $data, $order);
    }

    /**
     * 发起云闪付 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准银联跳转待支付结果
     */
    private function unionpayJsapiPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_BANK_JSAPI);
        $payment = $this->paymentPayload($order);
        $userId = trim((string) ($payment['unionpay_user_id'] ?? ''));
        if ($userId === '') {
            $authCode = trim((string) ($payment['unionpay_auth_code'] ?? ''));
            if ($authCode === '') {
                throw new PaymentDefinitiveException('银联前置银联 JSAPI 缺少 userAuth 授权结果', 40200);
            }
            $identifier = $this->configText('unionpay_app_up_identifier');
            if ($identifier === '') {
                throw new PaymentDefinitiveException('银联前置银联 JSAPI 缺少 appUpIdentifier', 40200);
            }
            $identity = $this->gatewayRequest([
                'service' => self::SERVICE_UNIONPAY_USER_ID,
                'user_auth_code' => $authCode,
                'app_up_identifier' => $identifier,
            ], '银联 userAuth 身份交换');
            $userId = $this->requiredResponseText($identity['user_id'] ?? '', '银联前置身份交换响应缺少 user_id');
        }

        $data = $this->gatewayRequest($this->basePayload($order) + [
            'service' => self::SERVICE_UNIONPAY_JSAPI,
            'user_id' => $userId,
        ], '银联 JSAPI 下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '银联 JSAPI 下单');
        $payUrl = $this->requiredResponseText($data['pay_url'] ?? '', '银联前置银联 JSAPI 响应缺少 pay_url');

        return $this->payResult('jump', 'bank', self::PRODUCT_BANK_JSAPI, self::SERVICE_UNIONPAY_JSAPI, [
            'url' => $payUrl,
        ], $data, $order);
    }

    /**
     * 发起微信 H5 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准微信 H5 待支付结果
     */
    private function wechatH5Pay(array $order): array
    {
        if ((string) ($order['pay_type_code'] ?? '') !== 'wxpay') {
            throw new UnsupportedPaymentOperationException('银联前置微信 H5 只支持 wxpay', 40200);
        }
        $this->ensureProduct(self::PRODUCT_WXPAY_H5);

        $returnUrl = $this->requiredText($order['return_url'] ?? '', '银联前置微信 H5 缺少 return_url');
        $data = $this->gatewayRequest($this->basePayload($order) + [
            'service' => self::SERVICE_WXPAY_H5,
            'device_info' => 'AND_WAP',
            'mch_app_name' => mb_strcut((string) $order['subject'], 0, 32, 'UTF-8'),
            'mch_app_id' => $returnUrl,
            'callback_url' => $returnUrl,
        ], '微信 H5 下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '微信 H5 下单');
        $payUrl = $this->requiredResponseText($data['pay_info'] ?? '', '银联前置微信 H5 响应缺少 pay_info');

        return $this->payResult('jump', 'wxpay', self::PRODUCT_WXPAY_H5, self::SERVICE_WXPAY_H5, [
            'url' => $payUrl,
        ], $data, $order);
    }

    /**
     * 构造微信公众号或小程序身份需求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否构造微信小程序身份需求
     *
     * @return array<string, mixed>|null
     */
    private function wechatIdentityRequirement(array $order, bool $mini): ?array
    {
        $payment = $this->paymentPayload($order);
        $identity = $mini
            ? trim((string) ($payment['mini_openid'] ?? ''))
            : $this->firstText($payment['openid'] ?? '', $payment['sub_openid'] ?? '');
        if ($identity !== '') {
            return null;
        }

        $appId = $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        $appSecret = $this->configText($mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret');
        if ($appId === '' || $appSecret === '') {
            throw new PaymentDefinitiveException($mini
                ? '银联前置微信小程序缺少身份，且未配置小程序 AppID/AppSecret'
                : '银联前置微信公众号缺少身份，且未配置公众号 AppID/AppSecret', 40200);
        }

        return [
            'provider' => 'wxpay',
            'product' => $mini ? 'mini' : 'mp',
            'channel_product' => $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'openid',
            'identity_aliases' => $mini ? [] : ['sub_openid'],
            'app_id' => $appId,
            '_app_secret' => $appSecret,
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wx_mini_launch_path') : '',
            'env_version' => 'release',
            'message' => $mini
                ? '银联前置微信小程序需要先取得 mini_openid'
                : '银联前置微信公众号需要先取得 openid',
        ];
    }

    /**
     * 构造支付宝服务窗身份需求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>|null
     */
    private function alipayIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['buyer_id'] ?? '')) !== '') {
            return null;
        }
        foreach (['alipay_oauth_app_id', 'alipay_oauth_private_key', 'alipay_oauth_public_key'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentDefinitiveException('银联前置支付宝服务窗缺少 buyer_id，且未配置完整授权参数', 40200);
            }
        }

        return [
            'provider' => 'alipay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
            'auth_type' => 'alipay_oauth',
            'identity_field' => 'buyer_id',
            'identity_aliases' => [],
            'app_id' => $this->configText('alipay_oauth_app_id'),
            'scope' => 'auth_base',
            '_alipay_config' => [
                'mode' => 'key',
                'app_id' => $this->configText('alipay_oauth_app_id'),
                'private_key' => $this->configText('alipay_oauth_private_key'),
                'alipay_public_key' => $this->configText('alipay_oauth_public_key'),
                'sandbox' => false,
            ],
            'message' => '银联前置支付宝服务窗需要先取得 buyer_id',
        ];
    }

    /**
     * 构造银联 userAuth 身份需求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>|null
     */
    private function unionpayIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['unionpay_user_id'] ?? '', $payment['unionpay_auth_code'] ?? '') !== '') {
            return null;
        }
        if ($this->configText('unionpay_app_up_identifier') === '') {
            throw new PaymentDefinitiveException('银联前置银联 JSAPI 缺少 appUpIdentifier', 40200);
        }

        return [
            'provider' => 'unionpay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_BANK_JSAPI,
            'auth_type' => 'unionpay_user_auth',
            'identity_field' => 'unionpay_auth_code',
            'identity_aliases' => [],
            'app_id' => '',
            'message' => '银联前置银联 JSAPI 需要先完成银联 userAuth 授权',
        ];
    }

    /**
     * 构造通用下单字段。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, string>
     */
    private function basePayload(array $order): array
    {
        return [
            'body' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
            'total_fee' => (string) $this->positiveCents($order['amount'] ?? null, '银联前置下单金额'),
            'mch_create_ip' => $this->requiredText($order['client_ip'] ?? '', '银联前置下单缺少 client_ip'),
            'out_trade_no' => $this->requiredText($order['pay_no'] ?? '', '银联前置下单缺少 pay_no'),
            'notify_url' => $this->requiredText($order['callback_url'] ?? '', '银联前置下单缺少 callback_url'),
        ];
    }

    /**
     * 调用网关并把确定拒绝与结果不确定映射为标准异常。
     *
     * @param array<string, mixed> $payload 请求字段
     * @param string $action 用于异常提示的支付动作
     *
     * @return array<string, mixed> 已验签且业务受理的响应
     */
    private function gatewayRequest(array $payload, string $action): array
    {
        try {
            $data = $this->client()->request($payload);
        } catch (UnionpaySdkException $e) {
            if ($e->isUncertain()) {
                throw new PaymentUncertainException('银联前置' . $action . '结果不确定：' . $e->getMessage(), 40200);
            }

            throw new PaymentDefinitiveException('银联前置' . $action . '失败：' . $e->getMessage(), 40200);
        }
        $this->assertResponseMerchant($data, $action, true);

        return $data;
    }

    /**
     * 包装标准支付产品结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 银联前置产品代码
     * @param string $action 实际调用的服务代码
     * @param array<string, mixed> $payParams 承接参数
     * @param array<string, mixed> $data 已验签响应
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function payResult(
        string $page,
        string $payType,
        string $product,
        string $action,
        array $payParams,
        array $data,
        array $order
    ): array {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => trim((string) ($data['transaction_id'] ?? '')),
        ]);
    }

    /**
     * 校验响应商户身份。
     *
     * @param array<string, mixed> $data 已验签响应
     * @param string $context 用于异常提示的业务场景
     * @param bool $uncertain 身份不匹配是否代表渠道结果不确定
     */
    private function assertResponseMerchant(array $data, string $context, bool $uncertain = false): void
    {
        $expectedMchId = $this->configText('mch_id');
        $actualMchId = trim((string) ($data['mch_id'] ?? ''));
        if ($expectedMchId === '' || $actualMchId === '' || !hash_equals($expectedMchId, $actualMchId)) {
            $this->throwResponseMismatch('银联前置' . $context . '响应商户号不一致', $uncertain);
        }
        $expectedSubMchId = $this->configText('sub_mch_id');
        $actualSubMchId = trim((string) ($data['sub_mch_id'] ?? ''));
        if (($expectedSubMchId !== '' || $actualSubMchId !== '')
            && ($expectedSubMchId === '' || $actualSubMchId === '' || !hash_equals($expectedSubMchId, $actualSubMchId))) {
            $this->throwResponseMismatch('银联前置' . $context . '响应子商户号不一致', $uncertain);
        }
    }

    private function throwResponseMismatch(string $message, bool $uncertain): never
    {
        if ($uncertain) {
            throw new PaymentUncertainException($message, 40200);
        }

        throw new PaymentException($message, 40200);
    }

    /**
     * 校验下单响应的商户订单号。
     *
     * @param array<string, mixed> $data 已验签响应
     * @param string $payNo 本地支付单号
     * @param string $context 用于异常提示的业务场景
     */
    private function assertResponseOrder(array $data, string $payNo, string $context): void
    {
        $this->assertSameText($payNo, $data['out_trade_no'] ?? '', '银联前置' . $context . '响应订单号不一致');
    }

    /**
     * 兼容两类合同字段并确认通知通信层成功。
     *
     * @param array<string, mixed> $payload 已验签通知参数
     */
    private function assertNotificationCommunicationSuccess(array $payload): void
    {
        if (array_key_exists('return_code', $payload)) {
            if (strtoupper(trim((string) $payload['return_code'])) !== 'SUCCESS') {
                throw new PaymentException('银联前置回调通信状态失败', 40200);
            }

            return;
        }
        if (array_key_exists('status', $payload)) {
            if (trim((string) $payload['status']) !== '0') {
                throw new PaymentException('银联前置回调通信状态失败', 40200);
            }

            return;
        }

        throw new PaymentException('银联前置回调缺少通信状态', 40200);
    }

    /**
     * 按通知合同类型判断业务层是否成功。
     *
     * @param array<string, mixed> $payload 已验签通知参数
     */
    private function notificationBusinessSuccess(array $payload): bool
    {
        $resultCode = $this->requiredText($payload['result_code'] ?? '', '银联前置回调缺少 result_code');

        return array_key_exists('return_code', $payload)
            ? strtoupper($resultCode) === 'SUCCESS'
            : $resultCode === '0';
    }

    /**
     * 校验通知对应支付单的通道、金额及已保存的渠道编号。
     *
     * @param PayOrder $payOrder 通知对应的本地支付单
     * @param int $amount 通知整数分金额
     * @param string $transactionId 通知渠道交易号
     */
    private function assertNotifyOrder(PayOrder $payOrder, int $amount, string $transactionId): void
    {
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('银联前置回调支付单不属于当前通道', 40200);
        }
        if ((int) $payOrder->pay_amount !== $amount) {
            throw new PaymentException('银联前置回调金额与本地支付单不一致', 40200);
        }
        $payNo = (string) $payOrder->pay_no;
        $storedOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedOrderNo !== '' && !hash_equals($storedOrderNo, $payNo)) {
            throw new PaymentException('银联前置回调与已保存渠道订单号不一致', 40200);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && $transactionId !== '' && !hash_equals($storedTradeNo, $transactionId)) {
            throw new PaymentException('银联前置回调与已保存渠道交易号不一致', 40200);
        }
    }

    /**
     * 解析 JSON 对象字段，禁止把非 JSON 字符串猜成其它产品参数。
     *
     * @param mixed $value 渠道字段原值
     * @param string $field 用于异常提示的字段名称
     *
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $field): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new PaymentUncertainException($field . '缺失', 40200);
        }
        try {
            $data = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentUncertainException($field . '不是合法 JSON', 40200);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new PaymentUncertainException($field . '不是 JSON 对象', 40200);
        }

        return $data;
    }

    private function qqQrcode(string $qrcode): string
    {
        $parts = parse_url($qrcode);
        if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== 'myun.tenpay.com') {
            return $qrcode;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $token = trim((string) ($query['t'] ?? ''));
        if ($token === '') {
            throw new PaymentUncertainException('银联前置 QQ 二维码缺少 t 参数', 40200);
        }

        return 'https://qpay.qq.com/qr/' . rawurlencode($token);
    }

    private function isWechatMiniOrder(array $order): bool
    {
        if ((string) ($order['pay_type_code'] ?? '') !== 'wxpay') {
            return false;
        }
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            return $method === 'mini';
        }

        return $this->firstText(
            $payment['mini_openid'] ?? '',
            $payment['wx_login_code'] ?? '',
            $payment['mini_code'] ?? ''
        ) !== '' || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function isBankJsapiOrder(array $order): bool
    {
        if ((string) ($order['pay_type_code'] ?? '') !== 'bank') {
            return false;
        }
        $payment = $this->paymentPayload($order);

        return strtolower(trim((string) ($payment['method'] ?? ''))) === 'jsapi'
            || $this->firstText($payment['unionpay_user_id'] ?? '', $payment['unionpay_auth_code'] ?? '') !== '';
    }

    /**
     * 读取标准支付载体参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>
     */
    private function paymentPayload(array $order): array
    {
        $payment = ((array) ($order['extra'] ?? []))['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('银联前置产品未在当前通道开通', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
                'product' => $product,
            ]);
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

        return is_array($products)
            ? array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $products)))
            : [];
    }

    private function positiveCents(mixed $value, string $field): int
    {
        $amount = $this->integerCents($value, $field);
        if ($amount <= 0) {
            throw new PaymentDefinitiveException($field . '必须大于 0', 40200);
        }

        return $amount;
    }

    private function integerCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+$/D', $text) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return (int) $text;
    }

    private function assertSameCents(int $expected, mixed $actual, string $field): void
    {
        $text = trim((string) $actual);
        if (preg_match('/^\d+$/D', $text) !== 1 || (int) $text !== $expected) {
            throw new PaymentUncertainException($field . '不一致', 40200);
        }
    }

    private function assertSameText(string $expected, mixed $actual, string $message): void
    {
        $actual = trim((string) $actual);
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new PaymentUncertainException($message, 40200);
        }
    }

    private function requiredText(mixed $value, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new PaymentException($message, 40200);
        }

        return $value;
    }

    private function requiredResponseText(mixed $value, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new PaymentUncertainException($message, 40200);
        }

        return $value;
    }

    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * 获取按当前商户、子商户和独立网关配置初始化的 SDK 客户端。
     */
    private function client(): UnionpayClient
    {
        if ($this->client === null) {
            $this->client = new UnionpayClient([
                'mch_id' => $this->configText('mch_id'),
                'sub_mch_id' => $this->configText('sub_mch_id'),
                'key' => $this->configText('key'),
                'gateway_url' => $this->configText('gateway_url'),
            ]);
        }

        return $this->client;
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
