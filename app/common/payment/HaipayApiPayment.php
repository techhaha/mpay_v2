<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\haipay\HaipayClient;
use app\common\sdk\haipay\HaipaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
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
 * 海科融通 SaaS V2 聚合支付插件。
 *
 * 提供支付宝、微信、银联的扫码、JSAPI、付款码、查单、关单和退款能力。
 * 插件按支付产品固定 pay_type/pay_mode，并在响应与通知中校验服务商、商户、订单、
 * 金额和产品上下文；银联关闭能力没有协议依据时不会向上游发送猜测请求。
 */
class HaipayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALI_JSAPI = 'ALI_JSAPI';
    private const PRODUCT_WX_JSAPI = 'WX_JSAPI';
    private const PRODUCT_ALI = 'ALI';
    private const PRODUCT_WX = 'WX';
    private const PRODUCT_UNIONQR = 'UNIONQR';
    private const PRODUCT_PASSIVE_PAY = 'passive_pay';

    private const PRODUCTS = [
        self::PRODUCT_ALI_JSAPI,
        self::PRODUCT_WX_JSAPI,
        self::PRODUCT_ALI,
        self::PRODUCT_WX,
        self::PRODUCT_UNIONQR,
        self::PRODUCT_PASSIVE_PAY,
    ];

    private ?HaipayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'haipay_api',
        'name' => '海科融通支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.hkrt.cn/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造海科融通支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付单仓库；未注入时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
    }

    /**
     * 初始化海科融通支付插件。
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
        $products = $this->directPaymentEnabledProductsField([
            self::PRODUCT_ALI_JSAPI => '支付宝 JSAPI',
            self::PRODUCT_WX_JSAPI => '微信公众号/小程序 JSAPI',
            self::PRODUCT_ALI => '支付宝扫码',
            self::PRODUCT_WX => '微信扫码（rainbow_legacy）',
            self::PRODUCT_UNIONQR => '银联扫码',
            self::PRODUCT_PASSIVE_PAY => '付款码支付',
        ]);
        // 当前机构文档未声明微信 Native；只有商户确认已开通时才允许手工开启。
        $products['value'] = [
            self::PRODUCT_ALI_JSAPI,
            self::PRODUCT_WX_JSAPI,
            self::PRODUCT_ALI,
            self::PRODUCT_UNIONQR,
            self::PRODUCT_PASSIVE_PAY,
        ];

        return [
            $this->inputField('access_id', 'AccessID', true),
            ['type' => 'password', 'field' => 'access_key', 'title' => 'AccessKey', 'value' => '', 'validate' => [['required' => true, 'message' => 'AccessKey不能为空']]],
            $this->inputField('agent_no', '服务商编号', true),
            $this->inputField('merchant_no', '商户号', true),
            $this->inputField('pn', '产品编号', true),
            $this->inputField('wx_mp_app_id', '微信公众号 AppID'),
            ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            $this->inputField('wx_mini_app_id', '微信小程序 AppID'),
            ['type' => 'password', 'field' => 'wx_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            $this->inputField('wx_mini_launch_path', '微信小程序支付承接路径'),
            $this->inputField('alipay_oauth_app_id', '支付宝生活号/应用 AppID'),
            ['type' => 'textarea', 'field' => 'alipay_oauth_private_key', 'title' => '支付宝 OAuth 应用私钥', 'value' => '', 'props' => ['rows' => 5]],
            ['type' => 'textarea', 'field' => 'alipay_oauth_public_key', 'title' => '支付宝公钥', 'value' => '', 'props' => ['rows' => 4]],
            ['type' => 'switch', 'field' => 'sandbox', 'title' => '显式启用测试环境', 'value' => false],
            [
                'type' => 'input',
                'field' => 'sandbox_gateway',
                'title' => 'Sandbox 测试网关',
                'value' => '',
                'tip' => '仅 sandbox=true 时使用；允许机构 HTTP 测试地址，生产模式固定使用 HTTPS 且不会回退。',
            ],
            $products,
        ];
    }

    /**
     * 声明支付宝、微信公众号或微信小程序的身份需求。
     *
     * 付款码不触发授权；支付宝和微信只在匹配的产品、环境与应用作用域下声明身份要求。
     *
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed>|null 身份要求；无需补充身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['auth_code'] ?? '') !== '') {
            return null;
        }
        $method = strtolower($this->firstText($payment['method'] ?? ''));
        if (!in_array($method, ['', 'jsapi', 'mini'], true)) {
            return null;
        }

        $payType = $this->payTypeCode($order);
        $env = strtolower($this->firstText($order['_env'] ?? 'pc'));
        if ($payType === 'alipay'
            && $env === 'alipay'
            && $method !== 'mini'
            && $this->productEnabled(self::PRODUCT_ALI_JSAPI)) {
            $this->assertAlipayAppScope($payment);
            if ($this->firstText($payment['buyer_id'] ?? '') !== '') {
                return null;
            }
            $this->requireConfiguration([
                'alipay_oauth_app_id',
                'alipay_oauth_private_key',
                'alipay_oauth_public_key',
            ], '海科融通支付宝身份流程配置不完整');

            return [
                'provider' => 'alipay',
                'product' => 'jsapi',
                'channel_product' => self::PRODUCT_ALI_JSAPI,
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
                    'sandbox' => $this->configBool('sandbox'),
                ],
                'message' => '海科融通支付宝 JSAPI 需要当前支付宝应用作用域的 buyer_id',
            ];
        }

        if ($payType !== 'wxpay' || !$this->productEnabled(self::PRODUCT_WX_JSAPI)) {
            return null;
        }
        $mini = $this->isMiniIntent($payment);
        if (!$mini && $env !== 'wechat') {
            return null;
        }

        $this->assertWechatAppScope($payment, $mini);
        $openid = $mini
            ? $this->firstText($payment['mini_openid'] ?? '')
            : $this->wechatMpOpenId($payment);
        if ($openid !== '') {
            return null;
        }

        $appIdField = $mini ? 'wx_mini_app_id' : 'wx_mp_app_id';
        $appSecretField = $mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret';
        $this->requireConfiguration(
            [$appIdField, $appSecretField],
            $mini ? '海科融通微信小程序身份流程配置不完整' : '海科融通微信公众号身份流程配置不完整'
        );

        return [
            'provider' => 'wxpay',
            'product' => $mini ? 'mini' : 'mp',
            'channel_product' => self::PRODUCT_WX_JSAPI,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'openid',
            'identity_aliases' => $mini ? [] : ['sub_openid'],
            'app_id' => $this->configText($appIdField),
            '_app_secret' => $this->configText($appSecretField),
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wx_mini_launch_path') : '',
            'mini_launch_type' => $mini && $env === 'wechat' ? 'url_link' : 'url_scheme',
            'env_version' => 'release',
            'message' => $mini
                ? '海科融通微信小程序支付需要当前小程序作用域的 mini_openid'
                : '海科融通微信公众号支付需要当前公众号作用域的 openid',
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        if ($this->payTypeCode($order) === 'wxpay'
            && $this->isMiniIntent($payment)
            && strtolower($this->firstText($payment['method'] ?? '')) !== 'qrcode') {
            // 小程序身份是显式调用意图；统一选择器没有独立 mini handler，按微信容器执行同一 WX/JSAPI 产品。
            $order['_env'] = 'wechat';
        }

        return $this->executeDirectPaymentProduct($order, [
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_PASSIVE_PAY,
                    'wxpay' => self::PRODUCT_PASSIVE_PAY,
                    'bank' => self::PRODUCT_PASSIVE_PAY,
                ],
                'handler' => fn (): array => $this->passivePay($order),
            ],
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALI_JSAPI,
                    'wxpay' => self::PRODUCT_WX_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'mini' => [
                'products' => [
                    'wxpay' => self::PRODUCT_WX_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALI,
                    'wxpay' => self::PRODUCT_WX,
                    'bank' => self::PRODUCT_UNIONQR,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ], '海科融通');
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        $payNo = $this->requiredLocalText($order['pay_no'] ?? '', '海科融通查单缺少 pay_no');
        $tradeNo = $this->requiredLocalText($order['chan_trade_no'] ?? '', '海科融通查单缺少 chan_trade_no');
        $data = $this->execute('/api/v2/pay/order-query', [
            'merch_no' => $this->configText('merchant_no'),
            'trade_no' => $tradeNo,
        ], '查单');

        $this->assertQueryResponse($data, $order, $payNo, $tradeNo);
        $channelStatus = $this->responseText($data['trade_status'] ?? '', '海科融通查单缺少 trade_status');
        $status = match ($channelStatus) {
            '1' => PaymentPluginStatusConstant::SUCCESS,
            '2' => PaymentPluginStatusConstant::FAILED,
            '3' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
        $amount = $this->yuanToCents($data['order_amount'] ?? null, '海科融通查单金额');
        if (isset($order['amount']) && (int) $order['amount'] > 0 && $amount !== (int) $order['amount']) {
            throw new PaymentUncertainException('海科融通查单金额与本地支付单不一致', 40200);
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => $channelStatus,
            'message' => $this->statusMessage($channelStatus, '支付'),
        ];
    }

    /**
     * 关闭普通主扫订单，或按付款码订单语义撤销未决交易。
     *
     * 当前 SaaS V2 文档只给出 close-order，且仅支持微信、支付宝；因此银联二维码
     * 和银联付款码不会发送未获证据的关闭请求。
     *
     * @param array<string, mixed> $order 标准关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $payNo = $this->requiredLocalText($order['pay_no'] ?? '', '海科融通关单缺少 pay_no');
        $tradeNo = $this->requiredLocalText($order['chan_trade_no'] ?? '', '海科融通关单缺少 chan_trade_no');
        $product = $this->requiredProduct($order, '关单');
        $payType = $this->payTypeCode($order);
        if ($product === self::PRODUCT_UNIONQR || $payType === 'bank') {
            throw new UnsupportedPaymentOperationException('海科融通当前协议不支持银联二维码关闭/撤销', 40200);
        }

        $data = $this->execute('/api/v2/pay/close-order', [
            'merch_no' => $this->configText('merchant_no'),
            'trade_no' => $tradeNo,
        ], $product === self::PRODUCT_PASSIVE_PAY ? '付款码撤销' : '关单');
        $this->assertOperationOrder($data, $payNo, $tradeNo, '关单');

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => $payNo,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo,
            'message' => $product === self::PRODUCT_PASSIVE_PAY ? '付款码撤销成功' : '关单成功',
        ];
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->requiredLocalText($order['pay_no'] ?? '', '海科融通退款缺少 pay_no');
        $tradeNo = $this->requiredLocalText($order['chan_trade_no'] ?? '', '海科融通退款缺少 chan_trade_no');
        $refundNo = $this->requiredLocalText($order['refund_no'] ?? '', '海科融通退款缺少 refund_no');
        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        if ($refundAmount <= 0) {
            throw new PaymentDefinitiveException('海科融通退款金额无效', 40200);
        }

        $data = $this->execute('/api/v2/pay/refund', [
            'agent_no' => $this->configText('agent_no'),
            'merch_no' => $this->configText('merchant_no'),
            'trade_no' => $tradeNo,
            'out_refund_no' => $refundNo,
            'refund_amount' => FormatHelper::amount($refundAmount),
            'pn' => $this->configText('pn'),
        ], '退款');

        $this->assertResponseEquals($refundNo, $data['out_refund_no'] ?? '', '海科融通退款单号不一致');
        if (isset($data['trade_no'])) {
            $this->assertResponseEquals($tradeNo, $data['trade_no'], '海科融通退款原交易号不一致');
        }
        if (isset($data['merch_no'])) {
            $this->assertResponseEquals($this->configText('merchant_no'), $data['merch_no'], '海科融通退款商户号不一致');
        }
        $this->assertResponseEquals(
            $this->channelPayType($this->payTypeCode($order)),
            $data['pay_type'] ?? '',
            '海科融通退款支付类型不一致'
        );
        $responseAmount = $this->yuanToCents($data['refund_amount'] ?? null, '海科融通退款金额');
        if ($responseAmount !== $refundAmount) {
            throw new PaymentUncertainException('海科融通退款响应金额不一致', 40200);
        }
        $channelStatus = $this->responseText($data['trade_status'] ?? '', '海科融通退款缺少 trade_status');
        if ($channelStatus === '2') {
            throw new PaymentDefinitiveException('海科融通退款明确失败', 40200, $this->channelCode($data));
        }
        $status = match ($channelStatus) {
            '1' => PaymentPluginStatusConstant::SUCCESS,
            '3' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
        $channelRefundNo = $this->responseText($data['refund_no'] ?? '', '海科融通退款缺少 refund_no');

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'message' => $this->statusMessage($channelStatus, '退款'),
        ];
    }

    /**
     * 校验并解析支付通知。
     *
     * 验签后核对服务商、商户、订单金额、渠道流水以及支付产品对应的 pay_type/pay_mode。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        try {
            $payload = json_decode($request->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('海科融通回调不是合法 JSON', 40200);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new PaymentException('海科融通回调结构无效', 40200);
        }
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('海科融通回调验签失败', 40200);
        }

        $this->assertNotifyEquals($this->configText('agent_no'), $payload['agent_no'] ?? '', '服务商编号');
        $this->assertNotifyEquals($this->configText('merchant_no'), $payload['merch_no'] ?? '', '商户号');
        $this->assertNotifyEquals($this->configText('pn'), $payload['pn'] ?? '', '产品编号');
        $status = $this->requiredNotifyText($payload['trade_status'] ?? '', '交易状态');
        if ($status !== '1') {
            throw new PaymentException('海科融通回调不是支付成功状态', 40200, ['channel_status' => $status]);
        }

        $payNo = $this->requiredNotifyText($payload['out_trade_no'] ?? '', '商户订单号');
        $tradeNo = $this->requiredNotifyText($payload['trade_no'] ?? '', '海科订单号');
        // bank_trade_no 是银行/收单流水，不能替代海科 trade_no；当前标准结果没有第二渠道流水槽位。
        $this->requiredNotifyText($payload['bank_trade_no'] ?? '', '银行/收单流水');
        $this->requiredNotifyText($payload['sub_mch_id'] ?? '', '渠道子商户号');
        $this->requiredNotifyText($payload['clear_status'] ?? '', '清算状态');
        $this->requiredNotifyText($payload['end_time'] ?? '', '支付完成时间');
        $payType = $this->requiredNotifyText($payload['pay_type'] ?? '', '支付类型');
        $payMode = $this->requiredNotifyText($payload['pay_mode'] ?? '', '支付模式');
        $amount = $this->yuanToCents($payload['order_amount'] ?? null, '海科融通回调金额');
        $this->yuanToCents($payload['total_amount'] ?? null, '海科融通回调订单金额');

        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('海科融通回调未匹配到本地支付单', 40200);
        }
        $this->assertNotifyOrder($payOrder, $amount, $tradeNo, $payType, $payMode);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $amount,
            'message' => '支付成功',
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => $status,
        ];
    }

    /**
     * 返回渠道要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '{"return_code":"SUCCESS"}';
    }

    /**
     * 返回渠道要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '{"return_code":"FAIL","return_msg":"FAIL"}';
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPay(array $order): array
    {
        $payType = $this->payTypeCode($order);
        $channelType = match ($payType) {
            'wxpay' => 'WX',
            'bank' => 'UNIONQR',
            default => 'ALI',
        };
        $data = $this->execute('/api/v2/pay/pre-pay', $this->basePayload($order) + [
            'pay_type' => $channelType,
            'pay_mode' => 'NATIVE',
        ], '扫码下单');
        $this->assertPaymentResponse($data, $order, $channelType, 'NATIVE', '扫码下单');

        $field = match ($channelType) {
            'ALI' => 'ali_qr_code',
            'WX' => 'wc_qr_code',
            'UNIONQR' => 'uniqr_qr_code',
        };
        $qrcode = trim((string) ($data[$field] ?? ''));
        if ($qrcode === '') {
            throw new PaymentUncertainException('海科融通已受理但缺少所选产品二维码字段', 40200, [
                'channel_error_code' => 'MISSING_' . strtoupper($field),
            ]);
        }

        return $this->payResult('qrcode', $payType, $channelType, 'pre-pay', ['qrcode' => $qrcode], $data, $order);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order): array
    {
        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        $channelType = $payType === 'wxpay' ? 'WX' : 'ALI';
        $payload = $this->basePayload($order) + ['pay_type' => $channelType, 'pay_mode' => 'JSAPI'];

        if ($payType === 'wxpay') {
            $mini = $this->isMiniIntent($payment);
            $this->assertWechatAppScope($payment, $mini);
            $payload['appid'] = $this->requiredLocalText(
                $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id'),
                $mini ? '海科融通微信小程序 AppID 未配置' : '海科融通微信公众号 AppID 未配置'
            );
            $payload['openid'] = $this->requiredLocalText(
                $mini ? ($payment['mini_openid'] ?? '') : $this->wechatMpOpenId($payment),
                $mini ? '海科融通微信小程序身份无效' : '海科融通微信公众号身份无效'
            );
        } else {
            $this->assertAlipayAppScope($payment);
            // SaaS V2 把支付宝生活号 user_id 命名为 openid；MPAY 输入仍严格使用 buyer_id。
            $payload['openid'] = $this->requiredLocalText(
                $payment['buyer_id'] ?? '',
                '海科融通支付宝 JSAPI 身份无效'
            );
        }

        $data = $this->execute('/api/v2/pay/pre-pay', $payload, 'JSAPI下单');
        $this->assertPaymentResponse($data, $order, $channelType, 'JSAPI', 'JSAPI下单');

        if ($payType === 'wxpay') {
            $payInfo = $this->jsonObject($data['wc_pay_data'] ?? '', '海科融通微信 JSAPI wc_pay_data');
        } else {
            $tradeNo = trim((string) ($data['ali_trade_no'] ?? ''));
            if ($tradeNo === '') {
                throw new PaymentUncertainException('海科融通已受理但缺少 ali_trade_no', 40200);
            }
            $payInfo = ['tradeNO' => $tradeNo];
        }

        return $this->payResult(
            'jsapi',
            $payType,
            $payType === 'wxpay' ? self::PRODUCT_WX_JSAPI : self::PRODUCT_ALI_JSAPI,
            'pre-pay',
            $payInfo,
            $data,
            $order
        );
    }

    /**
     * 发起付款码支付。
     *
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function passivePay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $authCode = $this->requiredLocalText($payment['auth_code'] ?? '', '海科融通付款码支付缺少 auth_code');
        $data = $this->execute('/api/v2/pay/passive-pay', $this->basePayload($order) + [
            'auth_code' => $authCode,
            'terminal_info' => ['device_ip' => $this->firstText($order['client_ip'] ?? '')],
        ], '付款码下单');

        $channelType = $this->channelPayType($this->payTypeCode($order));
        $this->assertPaymentResponse($data, $order, $channelType, null, '付款码下单');
        if (isset($data['pay_mode'])) {
            $this->assertResponseEquals('BARPAY', $data['pay_mode'], '海科融通付款码支付模式不一致');
        }
        $channelStatus = $this->responseText($data['trade_status'] ?? '', '海科融通付款码响应缺少 trade_status');
        if ($channelStatus === '2') {
            throw new PaymentDefinitiveException('海科融通付款码支付明确失败', 40200, $this->channelCode($data));
        }
        if ($channelStatus === '1') {
            return $this->successfulPaymentResult($order, [
                'paid_amount' => (int) ($order['amount'] ?? 0),
                'pay_type' => $this->payTypeCode($order),
                'pay_product' => self::PRODUCT_PASSIVE_PAY,
                'pay_action' => 'passive-pay',
                'chan_order_no' => $this->responseText($data['out_trade_no'] ?? '', '海科融通响应缺少 out_trade_no'),
                'chan_trade_no' => $this->responseText($data['trade_no'] ?? '', '海科融通响应缺少 trade_no'),
            ]);
        }
        if ($channelStatus !== '3') {
            throw new PaymentUncertainException('海科融通付款码交易状态未知', 40200, [
                'channel_status' => $channelStatus,
            ]);
        }

        return $this->payResult(
            'page',
            $this->payTypeCode($order),
            self::PRODUCT_PASSIVE_PAY,
            'passive-pay',
            [
                '_page' => 'paymentPending',
                'status' => PaymentPluginStatusConstant::PENDING,
                'channel_status' => $channelStatus,
                'description' => '付款码支付处理中，请勿重复扫码或改用其他产品；平台将按原海科订单号继续查单。',
            ],
            $data,
            $order
        );
    }

    /**
     * 构建上游公共请求参数。
     *
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed> 渠道公共请求参数
     */
    private function basePayload(array $order): array
    {
        return [
            'agent_no' => $this->configText('agent_no'),
            'merch_no' => $this->configText('merchant_no'),
            'out_trade_no' => $this->requiredLocalText($order['pay_no'] ?? '', '海科融通下单缺少 pay_no'),
            'total_amount' => FormatHelper::amount((int) ($order['amount'] ?? 0)),
            'pn' => $this->configText('pn'),
            'notify_url' => $this->requiredLocalText($order['callback_url'] ?? '', '海科融通下单缺少通知地址'),
            'extend_params' => [
                'body' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
                'subject' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
            ],
        ];
    }

    /**
     * 构建标准支付结果。
     *
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 海科融通产品编码
     * @param string $action 渠道接口动作
     * @param array<string, mixed> $payParams 标准承接参数
     * @param array<string, mixed> $data 渠道应答
     * @param array<string, mixed> $order 标准下单参数
     * @return array<string, mixed> 标准支付结果
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
            'chan_order_no' => $this->responseText($data['out_trade_no'] ?? '', '海科融通响应缺少 out_trade_no'),
            'chan_trade_no' => $this->responseText($data['trade_no'] ?? '', '海科融通响应缺少 trade_no'),
        ]);
    }

    /**
     * 校验支付响应。
     *
     * @param array<string, mixed> $data 渠道应答
     * @param array<string, mixed> $order 标准下单参数
     * @param string $payType 预期渠道支付类型
     * @param string|null $payMode 预期渠道支付模式；null 表示不校验
     * @param string $scene 业务场景说明
     * @return void
     */
    private function assertPaymentResponse(
        array $data,
        array $order,
        string $payType,
        ?string $payMode,
        string $scene
    ): void {
        $this->assertResponseEquals($this->configText('agent_no'), $data['agent_no'] ?? '', '海科融通' . $scene . '服务商编号不一致');
        $this->assertResponseEquals($this->configText('merchant_no'), $data['merch_no'] ?? '', '海科融通' . $scene . '商户号不一致');
        $this->assertResponseEquals((string) ($order['pay_no'] ?? ''), $data['out_trade_no'] ?? '', '海科融通' . $scene . '订单号不一致');
        $this->assertResponseEquals($payType, $data['pay_type'] ?? '', '海科融通' . $scene . '支付类型不一致');
        if ($payMode !== null) {
            $this->assertResponseEquals($payMode, $data['pay_mode'] ?? '', '海科融通' . $scene . '支付模式不一致');
        }
        $this->responseText($data['trade_no'] ?? '', '海科融通' . $scene . '缺少 trade_no');
    }

    /**
     * 校验查单响应。
     *
     * @param array<string, mixed> $data 渠道应答
     * @param array<string, mixed> $order 标准查单参数
     * @param string $payNo 预期平台支付单号
     * @param string $tradeNo 预期渠道交易号
     * @return void
     */
    private function assertQueryResponse(array $data, array $order, string $payNo, string $tradeNo): void
    {
        $this->assertResponseEquals($this->configText('agent_no'), $data['agent_no'] ?? '', '海科融通查单服务商编号不一致');
        $this->assertResponseEquals($this->configText('merchant_no'), $data['merch_no'] ?? '', '海科融通查单商户号不一致');
        $this->assertResponseEquals($payNo, $data['out_trade_no'] ?? '', '海科融通查单订单号不一致');
        $this->assertResponseEquals($tradeNo, $data['trade_no'] ?? '', '海科融通查单交易号不一致');
        $product = $this->requiredProduct($order, '查单');
        [$expectedType, $expectedMode] = $this->productProfile($product, $this->payTypeCode($order));
        $this->assertResponseEquals($expectedType, $data['pay_type'] ?? '', '海科融通查单支付类型不一致');
        $this->assertResponseEquals($expectedMode, $data['pay_mode'] ?? '', '海科融通查单支付模式不一致');
    }

    /**
     * 校验操作响应对应的支付单。
     *
     * @param array<string, mixed> $data 渠道应答
     * @param string $payNo 预期平台支付单号
     * @param string $tradeNo 预期渠道交易号
     * @param string $scene 业务场景说明
     * @return void
     */
    private function assertOperationOrder(array $data, string $payNo, string $tradeNo, string $scene): void
    {
        $this->assertResponseEquals($payNo, $data['out_trade_no'] ?? '', '海科融通' . $scene . '订单号不一致');
        $this->assertResponseEquals($tradeNo, $data['trade_no'] ?? '', '海科融通' . $scene . '交易号不一致');
    }

    /**
     * 校验通知与本地支付单及产品上下文一致。
     *
     * @param PayOrder $payOrder 本地支付单
     * @param int $amount 通知金额，单位分
     * @param string $tradeNo 通知渠道交易号
     * @param string $payType 通知渠道支付类型
     * @param string $payMode 通知渠道支付模式
     * @return void
     */
    private function assertNotifyOrder(
        PayOrder $payOrder,
        int $amount,
        string $tradeNo,
        string $payType,
        string $payMode
    ): void {
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('海科融通回调支付单不属于当前通道', 40200);
        }
        if ((int) $payOrder->pay_amount !== $amount) {
            throw new PaymentException('海科融通回调金额与本地支付单不一致', 40200);
        }
        $storedOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedOrderNo !== '' && !hash_equals((string) $payOrder->pay_no, $storedOrderNo)) {
            throw new PaymentException('海科融通本地渠道订单号上下文异常', 40200);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('海科融通回调 trade_no 与本地支付单不一致', 40200);
        }

        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $product = trim((string) ($context['pay_product'] ?? ''));
        $standardPayType = trim((string) ($context['pay_type'] ?? ''));
        if (!in_array($product, self::PRODUCTS, true)) {
            throw new PaymentException('海科融通回调缺少确定支付产品上下文', 40200);
        }
        [$expectedType, $expectedMode] = $this->productProfile($product, $standardPayType);
        if (!hash_equals($expectedType, $payType) || !hash_equals($expectedMode, $payMode)) {
            throw new PaymentException('海科融通回调产品或支付模式与本地订单不一致', 40200);
        }
    }

    /**
     * 获取支付产品档案。
     *
     * @param string $product 海科融通产品编码
     * @param string $payType 平台支付方式编码
     * @return array{0:string,1:string} 渠道 pay_type 与 pay_mode
     */
    private function productProfile(string $product, string $payType): array
    {
        return match ($product) {
            self::PRODUCT_ALI_JSAPI => ['ALI', 'JSAPI'],
            self::PRODUCT_WX_JSAPI => ['WX', 'JSAPI'],
            self::PRODUCT_ALI => ['ALI', 'NATIVE'],
            self::PRODUCT_WX => ['WX', 'NATIVE'],
            self::PRODUCT_UNIONQR => ['UNIONQR', 'NATIVE'],
            self::PRODUCT_PASSIVE_PAY => [$this->channelPayType($payType), 'BARPAY'],
            default => throw new PaymentException('海科融通支付产品上下文无效', 40200),
        };
    }

    /**
     * 将平台支付方式映射为渠道支付类型。
     *
     * @param string $payType 平台支付方式编码
     * @return string 渠道支付类型
     */
    private function channelPayType(string $payType): string
    {
        return match ($payType) {
            'alipay' => 'ALI',
            'wxpay' => 'WX',
            'bank' => 'UNIONQR',
            default => throw new PaymentDefinitiveException('海科融通支付方式无效', 40200),
        };
    }

    /**
     * 校验支付宝应用身份作用域。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @return void
     */
    private function assertAlipayAppScope(array $payment): void
    {
        $configured = $this->configText('alipay_oauth_app_id');
        if ($configured === '') {
            return;
        }
        foreach (['op_app_id', 'sub_appid'] as $field) {
            $provided = trim((string) ($payment[$field] ?? ''));
            if ($provided !== '' && !hash_equals($configured, $provided)) {
                throw new PaymentDefinitiveException('海科融通支付宝身份 AppID 作用域不一致', 40200);
            }
        }
    }

    /**
     * 校验微信应用身份作用域。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param bool $mini 是否为微信小程序支付
     * @return void
     */
    private function assertWechatAppScope(array $payment, bool $mini): void
    {
        $configured = $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        if ($configured === '') {
            return;
        }
        $fields = $mini ? ['mini_app_id', 'sub_appid'] : ['sub_appid'];
        foreach ($fields as $field) {
            $provided = trim((string) ($payment[$field] ?? ''));
            if ($provided !== '' && !hash_equals($configured, $provided)) {
                throw new PaymentDefinitiveException(
                    $mini ? '海科融通微信小程序 AppID 作用域不一致' : '海科融通微信公众号 AppID 作用域不一致',
                    40200
                );
            }
        }
    }

    /**
     * 读取微信公众号 OpenID。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @return string 当前公众号作用域的 OpenID
     */
    private function wechatMpOpenId(array $payment): string
    {
        $openid = trim((string) ($payment['openid'] ?? ''));
        $subOpenid = trim((string) ($payment['sub_openid'] ?? ''));
        if ($openid !== '' && $subOpenid !== '' && !hash_equals($openid, $subOpenid)) {
            throw new PaymentDefinitiveException('海科融通微信公众号 openid 与 sub_openid 不一致', 40200);
        }

        return $openid !== '' ? $openid : $subOpenid;
    }

    /**
     * 当前机构文档明确同一 WX/JSAPI 入口支持微信公众号与小程序；只有显式小程序意图
     * 或小程序身份字段才进入 mini 作用域，普通公众号参数不能替代 mini_openid。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @return bool 是否明确请求微信小程序支付
     */
    private function isMiniIntent(array $payment): bool
    {
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            return $method === 'mini';
        }

        return $this->firstText(
            $payment['mini_openid'] ?? '',
            $payment['mini_code'] ?? '',
            $payment['wx_login_code'] ?? '',
            $payment['mini_app_id'] ?? ''
        ) !== '' || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 读取标准支付载体参数。
     *
     * @param array<string, mixed> $order 标准参数
     * @return array<string, mixed> 支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 读取标准支付方式编码。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 平台支付方式编码
     */
    private function payTypeCode(array $order): string
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentDefinitiveException('海科融通不支持当前支付方式', 40200);
        }

        return $payType;
    }

    /**
     * 读取并校验支付产品。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @param string $scene 业务场景说明
     * @return string 下单产品快照
     */
    private function requiredProduct(array $order, string $scene): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if (!in_array($product, self::PRODUCTS, true)) {
            throw new PaymentDefinitiveException('海科融通' . $scene . '缺少确定支付产品上下文', 40200);
        }

        return $product;
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
                static fn (mixed $value): string => trim((string) $value),
                $products
            ))))
            : [];
    }

    private function productEnabled(string $product): bool
    {
        return in_array($product, $this->enabledProducts(), true);
    }

    private function validateConfiguration(): void
    {
        $this->requireConfiguration(
            ['access_id', 'access_key', 'agent_no', 'merchant_no', 'pn'],
            '海科融通基础配置不完整'
        );
        $products = $this->enabledProducts();
        if ($products === [] || array_diff($products, self::PRODUCTS) !== []) {
            throw new PaymentDefinitiveException('海科融通已开通产品为空或包含未知产品', 40200);
        }
        if ($this->configBool('sandbox') && $this->configText('sandbox_gateway') === '') {
            throw new PaymentDefinitiveException('海科融通 sandbox 必须显式配置测试网关', 40200);
        }

        $oauthFields = ['alipay_oauth_app_id', 'alipay_oauth_private_key', 'alipay_oauth_public_key'];
        $configuredOAuth = array_filter($oauthFields, fn (string $field): bool => $this->configText($field) !== '');
        if ($configuredOAuth !== [] && count($configuredOAuth) !== count($oauthFields)) {
            throw new PaymentDefinitiveException('海科融通支付宝 OAuth 配置必须完整', 40200);
        }
        foreach ([['wx_mp_app_id', 'wx_mp_app_secret'], ['wx_mini_app_id', 'wx_mini_app_secret']] as [$appId, $secret]) {
            if ($this->configText($secret) !== '' && $this->configText($appId) === '') {
                throw new PaymentDefinitiveException('海科融通微信身份 AppSecret 缺少对应 AppID', 40200);
            }
        }
    }

    /**
     * 校验一组必填通道配置。
     *
     * @param array<int, string> $fields 配置字段
     * @param string $message 配置不完整时的错误消息
     * @return void
     */
    private function requireConfiguration(array $fields, string $message): void
    {
        foreach ($fields as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentDefinitiveException($message, 40200, ['missing_config' => $field]);
            }
        }
    }

    /**
     * 执行上游 SDK 请求。
     *
     * SDK 已标记请求结果是否可确认，本方法据此保留确定失败或结果不确定语义。
     *
     * @param string $path 渠道接口路径
     * @param array<string, mixed> $payload 请求报文
     * @param string $scene 业务场景说明
     * @return array<string, mixed> 渠道响应
     */
    private function execute(string $path, array $payload, string $scene): array
    {
        try {
            return $this->client()->post($path, $payload);
        } catch (HaipaySdkException $e) {
            $exception = $e->isUncertain()
                ? PaymentUncertainException::class
                : PaymentDefinitiveException::class;
            $data = $e->channelCode() !== '' ? ['channel_error_code' => $e->channelCode()] : [];
            throw new $exception(
                $e->isUncertain() ? '海科融通' . $scene . '结果不确定' : '海科融通' . $scene . '被明确拒绝',
                40200,
                $data
            );
        }
    }

    /**
     * 获取当前通道的海科融通客户端。
     *
     * @return HaipayClient
     */
    private function client(): HaipayClient
    {
        if ($this->client === null) {
            try {
                $this->client = new HaipayClient([
                    'access_id' => $this->configText('access_id'),
                    'access_key' => $this->configText('access_key'),
                    'sandbox' => $this->configBool('sandbox'),
                    'sandbox_gateway' => $this->configText('sandbox_gateway'),
                ]);
            } catch (HaipaySdkException $e) {
                throw new PaymentDefinitiveException('海科融通 SDK 初始化失败', 40200, [
                    'channel_error_code' => $e->channelCode(),
                ]);
            }
        }

        return $this->client;
    }

    /**
     * 将渠道元金额转换为整数分。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
     */
    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $text, $matches) !== 1) {
            throw new PaymentUncertainException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
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
            throw new PaymentUncertainException($field . '缺失', 40200);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentUncertainException($field . '不是合法 JSON', 40200);
        }
        if (!is_array($decoded) || array_is_list($decoded) || $decoded === []) {
            throw new PaymentUncertainException($field . '结构无效', 40200);
        }

        return $decoded;
    }

    private function assertResponseEquals(string $expected, mixed $actual, string $message): void
    {
        $actual = trim((string) $actual);
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new PaymentUncertainException($message, 40200);
        }
    }

    private function responseText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentUncertainException($message, 40200);
        }

        return $text;
    }

    private function requiredLocalText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return $text;
    }

    private function requiredNotifyText(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentException('海科融通回调缺少' . $field, 40200);
        }

        return $text;
    }

    private function assertNotifyEquals(string $expected, mixed $actual, string $field): void
    {
        $actual = trim((string) $actual);
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException('海科融通回调' . $field . '与当前通道不一致', 40200);
        }
    }

    /**
     * 获取当前支付方式的上游通道编码。
     *
     * @param array<string, mixed> $data 渠道响应
     * @return array<string, string> 渠道错误码上下文
     */
    private function channelCode(array $data): array
    {
        $code = $this->firstText($data['error_code'] ?? '', $data['result_code'] ?? '');

        return $code !== '' ? ['channel_error_code' => $code] : [];
    }

    private function statusMessage(string $status, string $scene): string
    {
        return match ($status) {
            '1' => $scene . '成功',
            '2' => $scene . '失败',
            '3' => $scene . '处理中',
            default => $scene . '状态未知',
        };
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
        $result = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => ''];
        if ($required) {
            $result['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $result;
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
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
