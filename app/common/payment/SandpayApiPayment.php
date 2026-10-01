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
use app\common\interface\RefundNotifyInterface;
use app\common\interface\RefundQueryInterface;
use app\common\sdk\sandpay\SandpayClient;
use app\common\sdk\sandpay\SandpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use JsonException;
use support\Request;
use support\Response;

/**
 * 杉德收款 V4 API 插件。
 *
 * 负责聚合支付下单、支付查单、退款及支付/退款通知适配，并按支付单产品快照校验回调身份。
 * 杉德当前协议未提供可确认的关单接口，因此不会用本地状态模拟渠道关单成功。
 */
class SandpayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface,
    RefundQueryInterface,
    RefundNotifyInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private const MARKET_PRODUCTS = ['QZF', 'CSDB'];
    private const PAY_TYPE_ALIPAY = 'ALIPAY';
    private const PAY_TYPE_WECHAT = 'WXPAY';
    private const PAY_TYPE_UNIONPAY = 'CUPPAY';
    private const PAY_MODE_QR = 'QR';
    private const PAY_MODE_JSAPI = 'JSAPI';
    private const PAY_MODE_MINI = 'MINI';
    private const MAX_NOTIFICATION_BIZ_DATA_BYTES = 1024 * 1024;
    private const MAX_NOTIFICATION_SIGNATURE_BYTES = 4096;

    private ?SandpayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'sandpay_api',
        'name' => '杉德支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://api.sandpay.com.cn/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造杉德支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付通知归属校验所用仓储；为空时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
    }

    /**
     * 初始化插件。
     *
     * 初始化时清除旧客户端，并在产生任何渠道请求前校验证书、产品及身份授权配置。
     *
     * @param array<string, mixed> $channelConfig 当前支付通道配置
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
            $this->inputField('merchant_no', '商户编号', true),
            $this->inputField('merchant_cert_no', '商户证书序列号（杉德分配时填写）'),
            [
                'type' => 'password',
                'field' => 'private_cert_password',
                'title' => '商户 PFX 私钥证书密码',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户 PFX 私钥证书密码不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'public_cert_path',
                'title' => '杉德平台公钥证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [['required' => true, 'message' => '杉德平台公钥证书不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'private_cert_path',
                'title' => '商户 PFX 私钥证书',
                'value' => '',
                'props' => $this->uploadProps('.pfx,.p12'),
                'validate' => [['required' => true, 'message' => '商户 PFX 私钥证书不能为空']],
            ],
            [
                'type' => 'select',
                'field' => 'market_product',
                'title' => '市场产品',
                'value' => 'QZF',
                'options' => [
                    ['label' => '标准线上收款', 'value' => 'QZF'],
                    ['label' => '企业杉德宝', 'value' => 'CSDB'],
                ],
            ],
            $this->sandpayEnabledProductsField(),
            $this->inputField('wechat_mp_app_id', '微信公众号 AppID'),
            $this->passwordField('wechat_mp_app_secret', '微信公众号 AppSecret'),
            $this->inputField('wechat_mini_app_id', '微信小程序 AppID'),
            $this->passwordField('wechat_mini_app_secret', '微信小程序 AppSecret'),
            $this->inputField('wechat_mini_launch_path', '微信小程序支付承接路径'),
            [
                'type' => 'select',
                'field' => 'wechat_mini_env_version',
                'title' => '微信小程序版本',
                'value' => 'release',
                'options' => [
                    ['label' => '正式版', 'value' => 'release'],
                    ['label' => '体验版', 'value' => 'trial'],
                    ['label' => '开发版', 'value' => 'develop'],
                ],
            ],
            $this->inputField('alipay_oauth_app_id', '支付宝生活号/应用 AppID'),
            [
                'type' => 'textarea',
                'field' => 'alipay_oauth_private_key',
                'title' => '支付宝 OAuth 应用私钥',
                'value' => '',
                'props' => ['rows' => 5],
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_oauth_public_key',
                'title' => '支付宝公钥',
                'value' => '',
                'props' => ['rows' => 4],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '杉德测试环境',
                'value' => false,
                'props' => ['checkedText' => '测试', 'uncheckedText' => '生产'],
            ],
        ];
    }

    /**
     * 声明当前订单的支付身份需求。
     *
     * 仅为实际启用的支付宝 JSAPI、微信公众号或微信小程序产品声明对应应用作用域的用户身份。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>|null 缺少身份时的授权要求，已有身份或无需授权时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        $payType = $this->payTypeCode($order);
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));

        if ($payType === 'alipay'
            && $env === 'alipay'
            && in_array(self::PRODUCT_ALIPAY_JSAPI, $this->enabledProducts(), true)) {
            $this->assertPaymentAppScope($payment, $this->configText('alipay_oauth_app_id'), '支付宝');
            if ($this->firstText($payment['buyer_id'] ?? '') !== '') {
                return null;
            }

            return [
                'provider' => 'alipay',
                'product' => self::PRODUCT_ALIPAY_JSAPI,
                'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
                'auth_type' => 'alipay_oauth',
                'identity_field' => 'buyer_id',
                'app_id' => $this->configText('alipay_oauth_app_id'),
                'scope' => 'auth_base',
                '_alipay_config' => [
                    'mode' => 'key',
                    'app_id' => $this->configText('alipay_oauth_app_id'),
                    'private_key' => $this->configText('alipay_oauth_private_key'),
                    'alipay_public_key' => $this->configText('alipay_oauth_public_key'),
                    'sandbox' => $this->configBool('sandbox'),
                ],
                'message' => '杉德支付宝 JSAPI 需要先取得当前应用作用域的 buyer_id',
            ];
        }

        if ($payType !== 'wxpay') {
            return null;
        }

        $mini = $this->isMiniIntent($payment);
        if ($mini) {
            if (!in_array(self::PRODUCT_WXPAY_MINI, $this->enabledProducts(), true)) {
                throw new PaymentProductUnavailableException('当前杉德通道未开启微信小程序产品', 40200);
            }
            $this->assertPaymentAppScope($payment, $this->configText('wechat_mini_app_id'), '微信小程序');
            if ($this->firstText($payment['mini_openid'] ?? '') !== '') {
                return null;
            }

            return [
                'provider' => 'wxpay',
                'product' => 'mini',
                'channel_product' => self::PRODUCT_WXPAY_MINI,
                'auth_type' => 'mini_program',
                'identity_field' => 'mini_openid',
                'app_id' => $this->configText('wechat_mini_app_id'),
                '_app_secret' => $this->configText('wechat_mini_app_secret'),
                'mini_path' => $this->configText('wechat_mini_launch_path'),
                'env_version' => $this->miniEnvVersion(),
                'mini_launch_type' => $env === 'wechat' ? 'url_link' : 'url_scheme',
                'message' => '杉德微信小程序支付需要当前小程序作用域的 mini_openid',
            ];
        }

        if ($env !== 'wechat' || !in_array(self::PRODUCT_WXPAY_MP, $this->enabledProducts(), true)) {
            return null;
        }
        $this->assertPaymentAppScope($payment, $this->configText('wechat_mp_app_id'), '微信公众号');
        if ($this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '') !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => self::PRODUCT_WXPAY_MP,
            'channel_product' => self::PRODUCT_WXPAY_MP,
            'auth_type' => 'wechat_oauth',
            'identity_field' => 'openid',
            'app_id' => $this->configText('wechat_mp_app_id'),
            '_app_secret' => $this->configText('wechat_mp_app_secret'),
            'scope' => 'snsapi_base',
            'message' => '杉德微信公众号支付需要当前公众号作用域的 openid',
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    public function pay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        if ($this->payTypeCode($order) === 'wxpay' && $this->isMiniIntent($payment)) {
            $this->ensureProduct(self::PRODUCT_WXPAY_MINI);
            $order['_env'] = 'wechat';
        }
        $payType = $this->payTypeCode($order);
        $wxProduct = $this->isMiniIntent($payment) ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP;
        $jsapiHandler = $wxProduct === self::PRODUCT_WXPAY_MINI ? 'mini' : 'jsapi';

        return $this->executeDirectPaymentProduct($order, [
            $jsapiHandler => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI, 'wxpay' => $wxProduct],
                'handler' => fn (): array => $this->jsapiPay($order, $payType, $wxProduct),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                ],
                'handler' => fn (): array => $this->qrcodePayByType($order, $payType),
            ],
        ], '杉德');
    }

    /**
     * 查询支付订单。
     *
     * 查单响应须匹配当前商户、订单号、币种、已知金额及已保存的渠道交易号。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $payNo = $this->gatewayOrderNo($order['pay_no'] ?? '', '杉德查单 pay_no');
        try {
            $data = $this->client()->execute(SandpayClient::PATH_ORDER_QUERY, [
                'mid' => $this->configText('merchant_no'),
                'outOrderNo' => $payNo,
                'outReqTime' => date('YmdHis'),
            ]);
        } catch (SandpaySdkException $e) {
            throw new PaymentException('杉德查单失败：' . $e->getMessage(), 40200, ['pay_no' => $payNo]);
        }

        $this->assertResponseMerchant($data, '查单', true);
        $this->assertResponseOrder($data, $payNo, '查单', 'outOrderNo', true);
        $expectedAmount = $this->orderCents($order, ['amount']);
        if ($expectedAmount !== null) {
            $this->assertResponseAmount($data, $expectedAmount, '查单', true);
        }
        $this->assertCurrency($data, '查单');
        $status = $this->tradeStatus($this->firstText($data['orderStatus'] ?? '', $data['resultStatus'] ?? ''));
        $tradeNo = $this->firstText($data['sandSerialNo'] ?? '', $data['channelOrderNo'] ?? '');
        $storedTradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($status === PaymentPluginStatusConstant::SUCCESS && $tradeNo === '') {
            throw new PaymentException('杉德成功查单响应缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }
        if ($storedTradeNo !== '' && $tradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('杉德查单渠道交易号与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $expectedAmount : null,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo !== '' ? $tradeNo : $storedTradeNo,
            'channel_status' => strtolower($this->firstText($data['orderStatus'] ?? '', $data['resultStatus'] ?? '')),
            'message' => $this->safeMessage($data, '查单完成'),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->gatewayTime($data['finishedTime'] ?? '')
                : null,
        ];
    }

    /**
     * 关闭支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     *
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException(
            '杉德收款 V4 当前已核对产品文档未提供可确认的关单接口',
            40200,
            ['pay_no' => $this->gatewayOrderNo($order['pay_no'] ?? '', '杉德关单 pay_no')]
        );
    }

    /**
     * 发起退款。
     *
     * 渠道请求异常无法证明退款失败，因此按结果不确定处理并交由退款查单收敛终态。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     *
     * @return array<string, mixed> 标准退款受理结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->gatewayOrderNo($order['pay_no'] ?? '', '杉德退款原 pay_no');
        $refundNo = $this->gatewayOrderNo($order['refund_no'] ?? '', '杉德退款 refund_no');
        $refundAmount = $this->positiveCents($order['refund_amount'] ?? 0, '杉德退款金额必须大于 0 分');
        $payAmount = $this->orderCents($order, ['amount']);
        if ($payAmount !== null && $refundAmount > $payAmount) {
            throw new PaymentDefinitiveException('杉德退款金额不能大于原支付金额', 40200, ['refund_no' => $refundNo]);
        }
        $refundCallbackUrl = $this->requiredHttpUrl(
            $order['refund_callback_url'] ?? '',
            '杉德退款缺少独立退款通知地址'
        );

        try {
            $data = $this->client()->execute(SandpayClient::PATH_ORDER_REFUND, [
                'amount' => FormatHelper::amount($refundAmount),
                'marketProduct' => $this->marketProduct(),
                'mid' => $this->configText('merchant_no'),
                'notifyUrl' => $refundCallbackUrl,
                'oriOutOrderNo' => $payNo,
                'outOrderNo' => $refundNo,
                'outReqTime' => date('YmdHis'),
            ]);
        } catch (SandpaySdkException $e) {
            throw new PaymentUncertainException('杉德退款失败：' . $e->getMessage(), 40200, ['refund_no' => $refundNo]);
        }

        $this->assertResponseMerchant($data, '退款', false);
        $this->assertResponseOrder($data, $refundNo, '退款', 'outOrderNo', true);
        $this->assertResponseOrder($data, $payNo, '退款原交易', 'oriOutOrderNo', true);
        $this->assertResponseAmount($data, $refundAmount, '退款', true);
        $this->assertCurrency($data, '退款');
        $status = $this->refundStatus($this->firstText($data['orderStatus'] ?? '', $data['resultStatus'] ?? ''));
        if ($status === PaymentPluginStatusConstant::FAILED) {
            throw new PaymentDefinitiveException('杉德退款失败：' . $this->safeMessage($data, '渠道明确拒绝'), 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        $channelRefundNo = $this->firstText($data['sandSerialNo'] ?? '');
        if ($channelRefundNo === '') {
            throw new PaymentException('杉德退款响应缺少渠道退款号', 40200, ['refund_no' => $refundNo]);
        }

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'chan_refund_no' => $channelRefundNo,
            'refund_amount' => $refundAmount,
            'channel_status' => strtolower($this->firstText($data['orderStatus'] ?? '', $data['resultStatus'] ?? '')),
            'message' => $status === PaymentPluginStatusConstant::SUCCESS ? '退款成功' : '杉德已受理退款，等待终态',
        ];
    }

    /**
     * 查询退款状态。
     *
     * @param array<string, mixed> $refund 标准退款查询上下文
     *
     * @return array<string, mixed> 标准退款查询结果
     */
    public function queryRefund(array $refund): array
    {
        $refundNo = $this->gatewayOrderNo($refund['refund_no'] ?? '', '杉德退款查询 refund_no');
        $payNo = $this->gatewayOrderNo($refund['pay_no'] ?? '', '杉德退款查询 pay_no');
        $refundAmount = $this->positiveCents($refund['refund_amount'] ?? 0, '杉德退款查询金额必须大于 0 分');
        try {
            $data = $this->client()->execute(SandpayClient::PATH_ORDER_QUERY, [
                'mid' => $this->configText('merchant_no'),
                'outOrderNo' => $refundNo,
                'outReqTime' => date('YmdHis'),
            ]);
        } catch (SandpaySdkException $e) {
            throw new PaymentException('杉德退款查询失败：' . $e->getMessage(), 40200, ['refund_no' => $refundNo]);
        }

        $this->assertResponseMerchant($data, '退款查询', true);
        $this->assertResponseOrder($data, $refundNo, '退款查询', 'outOrderNo', true);
        $this->assertResponseOrder($data, $payNo, '退款查询原交易', 'oriOutOrderNo', true);
        $this->assertResponseAmount($data, $refundAmount, '退款查询', true);
        $this->assertCurrency($data, '退款查询');
        $status = $this->refundStatus($this->firstText($data['orderStatus'] ?? '', $data['resultStatus'] ?? ''));
        $channelRefundNo = $this->firstText($data['sandSerialNo'] ?? '');
        $storedChannelRefundNo = trim((string) ($refund['chan_refund_no'] ?? ''));
        if ($channelRefundNo === '') {
            throw new PaymentException('杉德退款查询响应缺少渠道退款号', 40200, ['refund_no' => $refundNo]);
        }
        if ($storedChannelRefundNo !== '' && !hash_equals($storedChannelRefundNo, $channelRefundNo)) {
            throw new PaymentException('杉德退款查询渠道退款号不匹配', 40200, ['refund_no' => $refundNo]);
        }

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => strtolower($this->firstText($data['orderStatus'] ?? '', $data['resultStatus'] ?? '')),
            'message' => $this->safeMessage($data, '退款查询完成'),
        ];
    }

    /**
     * 解析并校验支付通知。
     *
     * 除验签外，还按本地支付单核对通道、金额、产品快照和渠道交易号，避免跨通道或跨产品确认。
     *
     * @param Request $request 支付通知请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $this->verifiedNotification($request);
        if (strtolower($this->requiredText($payload['eventType'] ?? '', '杉德回调缺少 eventType')) !== 'recv') {
            throw new PaymentException('杉德支付回调事件类型不是 recv', 40200);
        }
        if (strtolower($this->requiredText($payload['orderStatus'] ?? '', '杉德回调缺少 orderStatus')) !== 'success') {
            throw new PaymentException('杉德支付回调不是成功终态', 40200);
        }

        $payNo = $this->gatewayOrderNo($payload['outOrderNo'] ?? '', '杉德回调 outOrderNo');
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('杉德回调支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('杉德回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }
        $this->assertNotificationMerchantAndProduct($payload, $payNo);
        $this->assertAmountMatches($payload['amount'] ?? '', (int) $payOrder->pay_amount, '杉德回调');
        $this->assertCurrency($payload, '回调');
        $this->assertNotifyProduct($payload, $payOrder);

        $tradeNo = $this->requiredText($payload['sandSerialNo'] ?? '', '杉德成功回调缺少 sandSerialNo');
        $storedTradeNo = $this->firstText($payOrder->channel_trade_no ?? '');
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('杉德回调渠道交易号与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'message' => '杉德支付成功',
            'pay_no' => $payNo,
            'paid_amount' => (int) $payOrder->pay_amount,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => 'success',
            'paid_at' => $this->gatewayTime($payload['finishedTime'] ?? ''),
        ];
    }

    /**
     * 解析并校验退款通知。
     *
     * @param Request $request 退款通知请求
     * @param array<string, mixed> $refund 本地退款单上下文
     *
     * @return array<string, mixed> 标准退款通知结果
     */
    public function refundNotify(Request $request, array $refund): array
    {
        $payload = $this->verifiedNotification($request);
        if (strtolower($this->requiredText($payload['eventType'] ?? '', '杉德退款回调缺少 eventType')) !== 'refund') {
            throw new PaymentException('杉德退款回调事件类型不是 refund', 40200);
        }
        $refundNo = $this->gatewayOrderNo($refund['refund_no'] ?? '', '杉德退款回调 refund_no');
        $payNo = $this->gatewayOrderNo($refund['pay_no'] ?? '', '杉德退款回调 pay_no');
        $refundAmount = $this->positiveCents($refund['refund_amount'] ?? 0, '杉德退款回调上下文金额无效');
        $this->assertNotificationMerchantAndProduct($payload, $refundNo);
        $this->assertResponseOrder($payload, $refundNo, '退款回调', 'outOrderNo', true);
        $this->assertResponseOrder($payload, $payNo, '退款回调原交易', 'oriOutOrderNo', true);
        $this->assertAmountMatches($payload['amount'] ?? '', $refundAmount, '杉德退款回调');
        $this->assertCurrency($payload, '退款回调');
        $status = $this->refundStatus($this->requiredText($payload['orderStatus'] ?? '', '杉德退款回调缺少 orderStatus'));
        $channelRefundNo = $this->requiredText($payload['sandSerialNo'] ?? '', '杉德退款回调缺少 sandSerialNo');
        $stored = trim((string) ($refund['chan_refund_no'] ?? ''));
        if ($stored !== '' && !hash_equals($stored, $channelRefundNo)) {
            throw new PaymentException('杉德退款回调渠道退款号不匹配', 40200, ['refund_no' => $refundNo]);
        }

        return [
            'status' => $status,
            'message' => '杉德退款状态通知',
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => strtolower((string) $payload['orderStatus']),
        ];
    }

    /**
     * 返回渠道要求的支付通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function notifySuccess(): string|Response
    {
        return 'respCode=000000';
    }

    /**
     * 返回渠道要求的支付通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function notifyFail(): string|Response
    {
        return 'respCode=020002';
    }

    /**
     * 返回渠道要求的退款通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function refundNotifySuccess(): string|Response
    {
        return 'respCode=000000';
    }

    /**
     * 返回渠道要求的退款通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function refundNotifyFail(): string|Response
    {
        return 'respCode=020002';
    }

    /**
     * 按支付方式发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function qrcodePayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'alipay' => $this->qrcodePay($order, self::PRODUCT_ALIPAY_SCAN, self::PAY_TYPE_ALIPAY),
            'wxpay' => $this->qrcodePay($order, self::PRODUCT_WXPAY_SCAN, self::PAY_TYPE_WECHAT),
            'bank' => $this->qrcodePay($order, self::PRODUCT_BANK_SCAN, self::PAY_TYPE_UNIONPAY),
            default => throw new PaymentException('杉德不支持当前扫码支付方式', 40200),
        };
    }

    /**
     * 发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 杉德产品代码
     * @param string $payType 杉德支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function qrcodePay(array $order, string $product, string $payType): array
    {
        $data = $this->requestCreateOrder($order, $product, $payType, self::PAY_MODE_QR);
        $credential = is_array($data['credential'] ?? null) ? $data['credential'] : [];
        $qrcode = $this->firstText($credential['qrCode'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('杉德扫码下单未返回二维码内容', 40200, ['pay_no' => (string) $order['pay_no']]);
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'qrcode',
            'pay_type' => match ($payType) {
                self::PAY_TYPE_ALIPAY => 'alipay',
                self::PAY_TYPE_WECHAT => 'wxpay',
                self::PAY_TYPE_UNIONPAY => 'bank',
            },
            'pay_product' => $product,
            'pay_action' => 'trans.order.create',
            'pay_params' => ['qrcode' => $qrcode],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) $data['sandSerialNo'],
        ]);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * 根据支付宝、公众号或小程序身份作用域构造用户标识，并校验微信响应 AppID 未跨应用。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payTypeCode 标准支付方式代码
     * @param string $wxProduct 已根据订单意图选出的微信产品代码
     *
     * @return array<string, mixed> 标准 JSAPI 待支付结果
     */
    private function jsapiPay(array $order, string $payTypeCode, string $wxProduct): array
    {
        $payment = $this->paymentPayload($order);
        if ($payTypeCode === 'alipay') {
            $product = self::PRODUCT_ALIPAY_JSAPI;
            $gatewayPayType = self::PAY_TYPE_ALIPAY;
            $payMode = self::PAY_MODE_JSAPI;
            $userId = $this->firstText($payment['buyer_id'] ?? '');
            $subAppId = '';
            $this->assertPaymentAppScope($payment, $this->configText('alipay_oauth_app_id'), '支付宝');
            if ($userId === '') {
                throw new PaymentException('杉德支付宝 JSAPI 缺少 buyer_id（不接受 buyer_open_id）', 40200);
            }
        } elseif ($payTypeCode === 'wxpay') {
            $mini = $this->isMiniIntent($payment);
            $product = $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP;
            if (!hash_equals($product, $wxProduct)) {
                throw new PaymentException('杉德微信产品选择与身份意图不一致', 40200);
            }
            $gatewayPayType = self::PAY_TYPE_WECHAT;
            $payMode = $mini ? self::PAY_MODE_MINI : self::PAY_MODE_JSAPI;
            $userId = $mini
                ? $this->firstText($payment['mini_openid'] ?? '')
                : $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
            $subAppId = $mini ? $this->configText('wechat_mini_app_id') : $this->configText('wechat_mp_app_id');
            $this->assertPaymentAppScope($payment, $subAppId, $mini ? '微信小程序' : '微信公众号');
            if ($userId === '') {
                throw new PaymentException($mini
                    ? '杉德微信小程序支付缺少 mini_openid'
                    : '杉德微信公众号支付缺少 openid', 40200);
            }
        } else {
            throw new PaymentException('杉德不支持当前 JSAPI 支付方式', 40200);
        }

        $data = $this->requestCreateOrder($order, $product, $gatewayPayType, $payMode, $userId, $subAppId);
        $credential = is_array($data['credential'] ?? null) ? $data['credential'] : [];
        if ($gatewayPayType === self::PAY_TYPE_ALIPAY) {
            $tradeNo = $this->firstText($credential['tradeNo'] ?? '');
            if ($tradeNo === '') {
                throw new PaymentException('杉德支付宝 JSAPI 未返回 tradeNo', 40200, ['pay_no' => (string) $order['pay_no']]);
            }
            $params = ['tradeNO' => $tradeNo];
        } else {
            $params = [
                'appId' => $this->firstText($credential['appId'] ?? ''),
                'timeStamp' => $this->firstText($credential['timeStamp'] ?? ''),
                'nonceStr' => $this->firstText($credential['nonceStr'] ?? ''),
                'package' => $this->firstText($credential['package'] ?? ''),
                'signType' => $this->firstText($credential['signType'] ?? ''),
                'paySign' => $this->firstText($credential['paySign'] ?? ''),
            ];
            if (in_array('', $params, true)) {
                throw new PaymentException('杉德微信 JSAPI 返回调起参数不完整', 40200, ['pay_no' => (string) $order['pay_no']]);
            }
            if (!hash_equals($subAppId, $params['appId'])) {
                throw new PaymentException('杉德微信 JSAPI 响应 AppID 与当前身份作用域不一致', 40200, ['pay_no' => (string) $order['pay_no']]);
            }
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jsapi',
            'pay_type' => $payTypeCode,
            'pay_product' => $product,
            'pay_action' => 'trans.order.create',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) $data['sandSerialNo'],
        ]);
    }

    /**
     * 请求渠道创建订单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 已启用的插件产品代码
     * @param string $payType 杉德支付方式代码
     * @param string $payMode 杉德支付模式代码
     * @param string $userId JSAPI 用户标识
     * @param string $subAppId 微信子应用 AppID
     *
     * @return array<string, mixed> 已完成基础身份与金额校验的上游响应
     */
    private function requestCreateOrder(
        array $order,
        string $product,
        string $payType,
        string $payMode,
        string $userId = '',
        string $subAppId = ''
    ): array {
        $this->ensureProduct($product);
        $payNo = $this->gatewayOrderNo($order['pay_no'] ?? '', '杉德下单 pay_no');
        $amount = $this->positiveCents($order['amount'] ?? 0, '杉德支付金额必须大于 0 分');
        $callbackUrl = $this->requiredHttpUrl($order['callback_url'] ?? '', '杉德下单 callback_url 无效');
        $sourceIp = $this->requiredIp($order['client_ip'] ?? '');
        $payload = [
            'amount' => FormatHelper::amount($amount),
            'description' => mb_strcut($this->requiredText($order['subject'] ?? '', '杉德下单主题不能为空'), 0, 128, 'UTF-8'),
            'goodsClass' => '01',
            'marketProduct' => $this->marketProduct(),
            'mid' => $this->configText('merchant_no'),
            'notifyUrl' => $callbackUrl,
            'outOrderNo' => $payNo,
            'outReqTime' => date('YmdHis'),
            'payMode' => $payMode,
            'payType' => $payType,
            'payerInfo' => ['payAccLimit' => ''],
            'riskmgtInfo' => ['sourceIp' => $sourceIp],
        ];
        if ($userId !== '' && $subAppId !== '') {
            $payload['payerInfo'] = [
                'frontUrl' => $this->requiredHttpUrl($order['return_url'] ?? '', '杉德 JSAPI 前台返回地址无效'),
                'subAppId' => $subAppId,
                'subUserId' => $userId,
            ];
        } elseif ($userId !== '') {
            $payload['payerInfo'] = [
                'frontUrl' => $this->requiredHttpUrl($order['return_url'] ?? '', '杉德 JSAPI 前台返回地址无效'),
                'userId' => $userId,
            ];
        }

        try {
            $data = $this->client()->execute(SandpayClient::PATH_ORDER_CREATE, $payload);
        } catch (SandpaySdkException $e) {
            throw new PaymentException('杉德下单失败：' . $e->getMessage(), 40200, ['pay_no' => $payNo]);
        }
        if (strtolower($this->firstText($data['resultStatus'] ?? '')) !== 'success') {
            throw new PaymentException('杉德下单未返回成功终态', 40200, ['pay_no' => $payNo]);
        }
        $this->assertResponseMerchant($data, '下单', false);
        $this->assertResponseOrder($data, $payNo, '下单', 'outOrderNo', false);
        if (array_key_exists('amount', $data)) {
            $this->assertResponseAmount($data, $amount, '下单', true);
        }
        $this->assertCurrency($data, '下单');
        if ($this->firstText($data['sandSerialNo'] ?? '') === '') {
            throw new PaymentException('杉德下单响应缺少 sandSerialNo', 40200, ['pay_no' => $payNo]);
        }

        return $data;
    }

    /**
     * 验签并解析渠道通知。
     *
     * 签名覆盖原始 bizData；解析前限制字段大小，避免对超大未受信输入执行验签和 JSON 解码。
     *
     * @param Request $request 渠道通知请求
     *
     * @return array<string, mixed> 验签通过的通知业务对象
     */
    private function verifiedNotification(Request $request): array
    {
        $bizData = $this->requiredText($request->post('bizData'), '杉德回调缺少 bizData');
        $sign = $this->requiredText($request->post('sign'), '杉德回调缺少 sign');
        if (strlen($bizData) > self::MAX_NOTIFICATION_BIZ_DATA_BYTES
            || strlen($sign) > self::MAX_NOTIFICATION_SIGNATURE_BYTES) {
            throw new PaymentException('杉德回调字段大小异常', 40200);
        }
        if (!$this->client()->verify($bizData, $sign)) {
            throw new PaymentException('杉德回调验签失败', 40200);
        }
        try {
            $payload = json_decode($bizData, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('杉德回调 bizData 不是合法 JSON', 40200);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new PaymentException('杉德回调 bizData 必须是 JSON 对象', 40200);
        }

        return $payload;
    }

    /**
     * 校验通知的商户和支付产品。
     *
     * @param array<string, mixed> $payload 已验签的通知业务对象
     * @param string $orderNo 用于异常定位的本地订单号
     */
    private function assertNotificationMerchantAndProduct(array $payload, string $orderNo): void
    {
        $mid = $this->requiredText($payload['mid'] ?? '', '杉德回调缺少 mid');
        if (!hash_equals($this->configText('merchant_no'), $mid)) {
            throw new PaymentException('杉德回调商户号不匹配', 40200, ['order_no' => $orderNo]);
        }
        $marketProduct = $this->requiredText($payload['marketProduct'] ?? '', '杉德回调缺少 marketProduct');
        if (!hash_equals($this->marketProduct(), $marketProduct)) {
            throw new PaymentException('杉德回调市场产品不匹配', 40200, ['order_no' => $orderNo]);
        }
    }

    /**
     * 校验通知支付产品。
     *
     * @param array<string, mixed> $payload 已验签的通知业务对象
     * @param PayOrder $payOrder 通知对应的本地支付单
     */
    private function assertNotifyProduct(array $payload, PayOrder $payOrder): void
    {
        $ext = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($ext['payment_context'] ?? []);
        $product = $this->firstText($context['pay_product'] ?? '');
        $expected = match ($product) {
            self::PRODUCT_ALIPAY_SCAN => [self::PAY_TYPE_ALIPAY, self::PAY_MODE_QR],
            self::PRODUCT_ALIPAY_JSAPI => [self::PAY_TYPE_ALIPAY, self::PAY_MODE_JSAPI],
            self::PRODUCT_WXPAY_SCAN => [self::PAY_TYPE_WECHAT, self::PAY_MODE_QR],
            self::PRODUCT_WXPAY_MP => [self::PAY_TYPE_WECHAT, self::PAY_MODE_JSAPI],
            self::PRODUCT_WXPAY_MINI => [self::PAY_TYPE_WECHAT, self::PAY_MODE_MINI],
            self::PRODUCT_BANK_SCAN => [self::PAY_TYPE_UNIONPAY, self::PAY_MODE_QR],
            default => throw new PaymentException('杉德回调无法识别支付单产品快照', 40200, ['pay_no' => (string) $payOrder->pay_no]),
        };
        $payType = strtoupper($this->requiredText($payload['payType'] ?? '', '杉德回调缺少 payType'));
        $payMode = strtoupper($this->requiredText($payload['payMode'] ?? '', '杉德回调缺少 payMode'));
        if (!hash_equals($expected[0], $payType) || !hash_equals($expected[1], $payMode)) {
            throw new PaymentException('杉德回调支付产品与支付单快照不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
    }

    /**
     * 校验渠道响应商户。
     *
     * @param array<string, mixed> $data 渠道响应或已验签通知
     * @param string $scene 用于异常提示的业务场景
     * @param bool $required 是否要求响应必须携带商户号
     */
    private function assertResponseMerchant(array $data, string $scene, bool $required): void
    {
        $mid = $this->firstText($data['mid'] ?? '');
        if ($mid === '' && $required) {
            throw new PaymentException('杉德' . $scene . '响应缺少 mid', 40200);
        }
        if ($mid !== '' && !hash_equals($this->configText('merchant_no'), $mid)) {
            throw new PaymentException('杉德' . $scene . '响应商户号不匹配', 40200);
        }
    }

    /**
     * 校验渠道响应订单。
     *
     * @param array<string, mixed> $data 渠道响应或已验签通知
     * @param string $expected 预期订单号
     * @param string $scene 用于异常提示的业务场景
     * @param string $field 渠道订单号字段
     * @param bool $required 是否要求响应必须携带该字段
     */
    private function assertResponseOrder(
        array $data,
        string $expected,
        string $scene,
        string $field,
        bool $required
    ): void {
        $actual = $this->firstText($data[$field] ?? '');
        if ($actual === '' && $required) {
            throw new PaymentException('杉德' . $scene . '响应缺少 ' . $field, 40200, ['expected_order_no' => $expected]);
        }
        if ($actual !== '' && !hash_equals($expected, $actual)) {
            throw new PaymentException('杉德' . $scene . '响应订单号不匹配', 40200, ['expected_order_no' => $expected]);
        }
    }

    /**
     * 校验渠道响应金额。
     *
     * @param array<string, mixed> $data 渠道响应或已验签通知
     * @param int $expectedCents 本地预期分金额
     * @param string $scene 用于异常提示的业务场景
     * @param bool $required 是否要求响应必须携带金额
     */
    private function assertResponseAmount(array $data, int $expectedCents, string $scene, bool $required): void
    {
        if (!array_key_exists('amount', $data)) {
            if ($required) {
                throw new PaymentException('杉德' . $scene . '响应缺少 amount', 40200);
            }
            return;
        }
        $this->assertAmountMatches($data['amount'], $expectedCents, '杉德' . $scene);
    }

    /**
     * 将渠道元金额与本地分金额作精确比较。
     *
     * @param mixed $yuan 渠道元金额原值
     * @param int $expectedCents 本地预期分金额
     * @param string $scene 用于异常提示的业务场景
     */
    private function assertAmountMatches(mixed $yuan, int $expectedCents, string $scene): void
    {
        $actualCents = $this->yuanToCents($yuan, $scene);
        if ($actualCents !== $expectedCents) {
            throw new PaymentException($scene . '金额与 MPAY 分金额不一致', 40200, [
                'actual_cents' => $actualCents,
                'expected_cents' => $expectedCents,
            ]);
        }
    }

    /**
     * 将渠道元金额严格换算为分，拒绝负数及超过两位的小数。
     *
     * @param mixed $yuan 渠道元金额原值
     * @param string $scene 用于异常提示的业务场景
     */
    private function yuanToCents(mixed $yuan, string $scene): int
    {
        $value = is_scalar($yuan) ? trim((string) $yuan) : '';
        if (preg_match('/^(0|[1-9]\d{0,12})(?:\.(\d{1,2}))?$/D', $value, $matches) !== 1) {
            throw new PaymentException($scene . '金额格式无效', 40200);
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    /**
     * 校验渠道币种。
     *
     * @param array<string, mixed> $data 渠道响应或已验签通知
     * @param string $scene 用于异常提示的业务场景
     */
    private function assertCurrency(array $data, string $scene): void
    {
        $currency = strtoupper($this->firstText(
            $data['currency'] ?? '',
            $data['currencyCode'] ?? '',
            $data['settleCurrency'] ?? ''
        ));
        if ($currency !== '' && !hash_equals('CNY', $currency)) {
            throw new PaymentException('杉德' . $scene . '币种不是 CNY', 40200, ['currency' => $currency]);
        }
    }

    private function tradeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success' => PaymentPluginStatusConstant::SUCCESS,
            'fail', 'failed' => PaymentPluginStatusConstant::FAILED,
            'closed', 'cancel', 'cancelled', 'canceled' => PaymentPluginStatusConstant::CLOSED,
            'process', 'processing', 'accept', 'pending', 'created' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
    }

    private function refundStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success' => PaymentPluginStatusConstant::SUCCESS,
            'fail', 'failed' => PaymentPluginStatusConstant::FAILED,
            'process', 'processing', 'accept', 'pending', 'created' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
    }

    private function gatewayTime(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^\d{14}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!YmdHis', $value);

        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * 获取使用商户私有证书与杉德平台证书初始化的 SDK 客户端。
     */
    private function client(): SandpayClient
    {
        if ($this->client === null) {
            try {
                $this->client = new SandpayClient([
                    'merchant_no' => $this->configText('merchant_no'),
                    'merchant_cert_no' => $this->configText('merchant_cert_no'),
                    'private_cert_password' => $this->configText('private_cert_password'),
                    'public_cert_path' => $this->privateCertificatePath(
                        $this->configText('public_cert_path'),
                        ['cer', 'crt', 'pem']
                    ),
                    'private_cert_path' => $this->privateCertificatePath(
                        $this->configText('private_cert_path'),
                        ['pfx', 'p12']
                    ),
                    'sandbox' => $this->configBool('sandbox'),
                ]);
            } catch (SandpaySdkException $e) {
                throw new PaymentException('杉德证书或 SDK 初始化失败：' . $e->getMessage(), 40200);
            }
        }

        return $this->client;
    }

    /**
     * 将私有证书 object_key 解析为受控目录内的真实文件路径。
     *
     * 语法校验、realpath 归一化和目录边界校验共同阻止路径穿越及符号链接越界。
     *
     * @param string $objectKey MPAY 私有证书对象键
     * @param array<int, string> $extensions 当前证书字段允许的扩展名
     */
    private function privateCertificatePath(string $objectKey, array $extensions): string
    {
        $objectKey = str_replace('\\', '/', trim($objectKey));
        $segments = explode('/', $objectKey);
        if ($objectKey === ''
            || str_contains($objectKey, "\0")
            || str_contains($objectKey, '://')
            || str_starts_with($objectKey, '/')
            || preg_match('/^[A-Za-z]:\//D', $objectKey) === 1
            || preg_match('#^storage/private/certificate/[A-Za-z0-9][A-Za-z0-9_./-]*$#D', $objectKey) !== 1
            || array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []) {
            throw new PaymentException('杉德证书配置必须是 MPAY 私有证书 object_key', 40200);
        }
        $extension = strtolower(pathinfo($objectKey, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true)) {
            throw new PaymentException('杉德证书 object_key 后缀不符合字段要求', 40200);
        }

        $root = realpath(runtime_path(FileConstant::LOCAL_PRIVATE_DIR . '/certificate'));
        $resolved = realpath(runtime_path($objectKey));
        if (!is_string($root) || !is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
            throw new PaymentException('杉德私有证书文件不存在或不可读', 40200);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $resolvedNormalized = str_replace('\\', '/', $resolved);
        $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        $comparisonResolved = DIRECTORY_SEPARATOR === '\\' ? strtolower($resolvedNormalized) : $resolvedNormalized;
        if (!str_starts_with($comparisonResolved, $comparisonRoot)) {
            throw new PaymentException('杉德证书 object_key 越出 MPAY 私有证书目录', 40200);
        }

        return $resolved;
    }

    /**
     * 校验渠道请求所需的证书、产品和身份授权配置。
     */
    private function validateConfiguration(): void
    {
        foreach (['merchant_no', 'private_cert_password', 'public_cert_path', 'private_cert_path'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException('杉德配置缺少 ' . $field, 40200);
            }
        }
        if (!in_array($this->marketProduct(), self::MARKET_PRODUCTS, true)) {
            throw new PaymentException('杉德市场产品配置无效', 40200);
        }
        $supported = [
            self::PRODUCT_ALIPAY_SCAN,
            self::PRODUCT_ALIPAY_JSAPI,
            self::PRODUCT_WXPAY_SCAN,
            self::PRODUCT_WXPAY_MP,
            self::PRODUCT_WXPAY_MINI,
            self::PRODUCT_BANK_SCAN,
        ];
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, $supported) !== []) {
            throw new PaymentException('杉德已开通产品配置为空或包含未知产品', 40200);
        }
        $this->assertPrivateObjectKeySyntax($this->configText('public_cert_path'), ['cer', 'crt', 'pem']);
        $this->assertPrivateObjectKeySyntax($this->configText('private_cert_path'), ['pfx', 'p12']);
        if (in_array(self::PRODUCT_WXPAY_MP, $enabled, true)) {
            $this->assertIdentityConfig(['wechat_mp_app_id', 'wechat_mp_app_secret'], '杉德微信公众号产品');
        }
        if (in_array(self::PRODUCT_WXPAY_MINI, $enabled, true)) {
            $this->assertIdentityConfig(['wechat_mini_app_id', 'wechat_mini_app_secret'], '杉德微信小程序产品');
            $this->miniEnvVersion();
        }
        if (in_array(self::PRODUCT_ALIPAY_JSAPI, $enabled, true)) {
            $this->assertIdentityConfig([
                'alipay_oauth_app_id',
                'alipay_oauth_private_key',
                'alipay_oauth_public_key',
            ], '杉德支付宝 JSAPI 身份流程');
        }
    }

    /**
     * 校验私有文件对象键。
     *
     * 此处仅校验配置语法；文件存在性及最终目录边界在创建客户端时校验。
     *
     * @param string $objectKey MPAY 私有证书对象键
     * @param array<int, string> $extensions 当前证书字段允许的扩展名
     */
    private function assertPrivateObjectKeySyntax(string $objectKey, array $extensions): void
    {
        $objectKey = str_replace('\\', '/', trim($objectKey));
        $segments = explode('/', $objectKey);
        if (preg_match('#^storage/private/certificate/[A-Za-z0-9][A-Za-z0-9_./-]*$#D', $objectKey) !== 1
            || array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []
            || !in_array(strtolower(pathinfo($objectKey, PATHINFO_EXTENSION)), $extensions, true)) {
            throw new PaymentException('杉德证书配置必须是 MPAY 私有证书 object_key', 40200);
        }
    }

    /**
     * 校验身份授权配置。
     *
     * @param array<int, string> $fields 必填配置键
     * @param string $label 用于异常提示的身份产品名称
     */
    private function assertIdentityConfig(array $fields, string $label): void
    {
        foreach ($fields as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException($label . '必须配置 ' . $field, 40200);
            }
        }
    }

    /**
     * 获取已启用支付产品。
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
                static fn (mixed $value): string => trim((string) $value),
                $products
            ))))
            : [];
    }

    /**
     * 确认订单选出的产品已在当前通道显式启用。
     *
     * @param string $product 插件产品代码
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前杉德通道未开启该支付产品', 40200, ['product' => $product]);
        }
    }

    /**
     * 获取支付方式编码。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function payTypeCode(array $order): string
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentException('杉德不支持当前支付方式', 40200, ['pay_type' => $payType]);
        }

        return $payType;
    }

    /**
     * 获取支付扩展参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 归一化后的支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? $order['ext_json'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 判断是否请求小程序支付。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     */
    private function isMiniIntent(array $payment): bool
    {
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            return $method === 'mini';
        }

        return $this->firstText($payment['mini_openid'] ?? '') !== ''
            || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 校验支付身份所属应用。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $expectedAppId 当前产品配置的应用 AppID
     * @param string $label 用于异常提示的应用类型
     */
    private function assertPaymentAppScope(array $payment, string $expectedAppId, string $label): void
    {
        if ($expectedAppId === '') {
            throw new PaymentException($label . ' AppID 未配置', 40200);
        }
        $provided = $this->firstText($payment['sub_appid'] ?? '', $payment['app_id'] ?? '');
        if ($provided !== '' && !hash_equals($expectedAppId, $provided)) {
            throw new PaymentException($label . '身份 AppID 与当前产品配置不一致', 40200);
        }
    }

    /**
     * 读取订单整数分金额。
     *
     * @param array<string, mixed> $order 订单或退款上下文
     * @param array<int, string> $fields 按优先级读取的金额字段
     */
    private function orderCents(array $order, array $fields): ?int
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $order)
                && (is_int($order[$field]) || (is_string($order[$field]) && preg_match('/^\d+$/D', $order[$field]) === 1))) {
                return (int) $order[$field];
            }
        }

        return null;
    }

    private function positiveCents(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new PaymentDefinitiveException($message, 40200);
        }
        $amount = (int) $value;
        if ($amount <= 0) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return $amount;
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if ($text === '') {
            throw new PaymentException($message, 40200);
        }

        return $text;
    }

    private function requiredHttpUrl(mixed $value, string $message): string
    {
        $url = $this->requiredText($value, $message);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new PaymentException($message, 40200);
        }

        return $url;
    }

    private function requiredIp(mixed $value): string
    {
        $ip = $this->requiredText($value, '杉德下单 client_ip 不能为空');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new PaymentException('杉德下单 client_ip 无效', 40200);
        }

        return $ip;
    }

    private function gatewayOrderNo(mixed $value, string $label): string
    {
        $orderNo = $this->requiredText($value, $label . ' 不能为空');
        if (preg_match('/^[A-Za-z0-9_-]{10,50}$/D', $orderNo) !== 1) {
            throw new PaymentException($label . ' 必须是 10-50 位字母、数字、下划线或短横线', 40200);
        }

        return $orderNo;
    }

    /**
     * 提取可安全展示的渠道信息。
     *
     * 控制字符会被移除，文本长度也会受限，避免直接透传不可控的上游内容。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $fallback 未取得可用信息时的默认文案
     */
    private function safeMessage(array $data, string $fallback): string
    {
        $message = $this->firstText($data['errorDesc'] ?? '', $data['orderStatus'] ?? '', $data['resultStatus'] ?? '');
        $message = preg_replace('/[\x00-\x1F\x7F]/u', '', $message) ?: '';

        return $message !== '' ? mb_strcut($message, 0, 160, 'UTF-8') : $fallback;
    }

    private function marketProduct(): string
    {
        return strtoupper($this->configText('market_product') ?: 'QZF');
    }

    private function miniEnvVersion(): string
    {
        $value = strtolower($this->configText('wechat_mini_env_version') ?: 'release');
        if (!in_array($value, ['release', 'trial', 'develop'], true)) {
            throw new PaymentException('杉德微信小程序版本配置无效', 40200);
        }

        return $value;
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    private function configBool(string $key): bool
    {
        return filter_var($this->getConfig($key, false), FILTER_VALIDATE_BOOL);
    }

    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * 构建杉德已开通产品配置字段。
     *
     * @return array<string, mixed>
     */
    private function sandpayEnabledProductsField(): array
    {
        $field = $this->directPaymentEnabledProductsField([
            self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
            self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
            self::PRODUCT_WXPAY_SCAN => '微信扫码',
            self::PRODUCT_WXPAY_MP => '微信公众号 JSAPI',
            self::PRODUCT_WXPAY_MINI => '微信小程序',
            self::PRODUCT_BANK_SCAN => '银联/云闪付扫码',
        ]);
        $field['value'] = [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_WXPAY_SCAN, self::PRODUCT_BANK_SCAN];

        return $field;
    }

    /**
     * 构建文本输入配置字段。
     *
     * @param string $field 配置字段名
     * @param string $title 表单标题
     * @param bool $required 是否添加必填校验
     *
     * @return array<string, mixed>
     */
    private function inputField(string $field, string $title, bool $required = false): array
    {
        $result = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => ''];
        if ($required) {
            $result['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $result;
    }

    /**
     * 构建密码输入配置字段。
     *
     * @param string $field 配置字段名
     * @param string $title 表单标题
     *
     * @return array<string, mixed>
     */
    private function passwordField(string $field, string $title): array
    {
        return ['type' => 'password', 'field' => $field, 'title' => $title, 'value' => ''];
    }

    /**
     * 构建私有文件上传配置。
     *
     * @param string $accept 允许上传的扩展名列表
     *
     * @return array<string, mixed>
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
}
