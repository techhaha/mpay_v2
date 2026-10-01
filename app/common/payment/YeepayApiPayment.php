<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\yeepay\YeepaySdkException;
use app\common\sdk\yeepay\YeepayYopClient;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use support\Request;
use support\Response;

/**
 * 易宝聚合支付。
 *
 * 负责聚合预下单、托管 H5/APP、小程序支付，以及支付通知、查单、关单和退款适配。
 * 直付身份按支付宝应用、微信公众号或微信小程序作用域区分，不能跨产品复用用户标识。
 */
class YeepayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_ALIPAY_APP = 'alipay_app';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_WXPAY_H5 = 'wxpay_h5';
    private const PRODUCT_WXPAY_APP = 'wxpay_app';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private const PATH_PREPAY = '/rest/v1.0/aggpay/pre-pay';
    private const PATH_TUTELAGE_PREPAY = '/rest/v1.0/aggpay/tutelage/pre-pay';
    private const PATH_QUERY = '/rest/v1.0/trade/order/query';
    private const PATH_CLOSE = '/rest/v1.0/aggpay/close-order';
    private const PATH_REFUND = '/rest/v1.0/trade/refund';

    private const API_CODE_PREPAY_SUCCESS = '00000';
    private const API_CODE_OPERATION_SUCCESS = 'OPR00000';

    /**
     * @var array<string, string>
     */
    private const PRODUCT_OPTIONS = [
        self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
        self::PRODUCT_ALIPAY_JSAPI => '支付宝生活号 JSAPI',
        self::PRODUCT_ALIPAY_APP => '支付宝托管 SDK/APP',
        self::PRODUCT_WXPAY_SCAN => '微信扫码',
        self::PRODUCT_WXPAY_MP => '微信公众号',
        self::PRODUCT_WXPAY_MINI => '微信小程序直付',
        self::PRODUCT_WXPAY_H5 => '微信托管 H5',
        self::PRODUCT_WXPAY_APP => '微信托管 SDK/小程序',
        self::PRODUCT_BANK_SCAN => '云闪付扫码',
    ];

    private ?YeepayYopClient $client = null;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'yeepay_api',
        'name' => '易宝聚合支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://open.yeepay.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 初始化插件。
     *
     * 初始化时清除旧客户端，并在请求渠道前校验密钥、网关、场景及已开通产品的身份配置。
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
            $this->inputField('app_key', 'YOP 应用标识', true),
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户 RSA 私钥（PKCS#1/PKCS#8）',
                'value' => '',
                'props' => ['rows' => 6],
                'validate' => [['required' => true, 'message' => '商户 RSA 私钥不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '易宝平台 RSA 公钥',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [['required' => true, 'message' => '易宝平台 RSA 公钥不能为空']],
            ],
            $this->inputField('parent_merchant_no', '发起方商户编号', true),
            $this->inputField('merchant_no', '收款商户编号（留空同发起方）'),
            [
                'type' => 'select',
                'field' => 'wechat_scene',
                'title' => '微信支付场景',
                'value' => 'ONLINE',
                'options' => [
                    ['label' => '线上', 'value' => 'ONLINE'],
                    ['label' => '线下', 'value' => 'OFFLINE'],
                ],
            ],
            [
                'type' => 'select',
                'field' => 'alipay_scene',
                'title' => '支付宝支付场景',
                'value' => 'OFFLINE',
                'options' => [
                    ['label' => '线下', 'value' => 'OFFLINE'],
                    ['label' => '大额', 'value' => 'LARGE'],
                    ['label' => '挂号', 'value' => 'REGISTRATION'],
                ],
            ],
            $this->inputField('wechat_mp_app_id', '微信公众号 AppID'),
            $this->passwordField('wechat_mp_app_secret', '微信公众号 AppSecret'),
            [
                'type' => 'switch',
                'field' => 'bindwxa',
                'title' => '允许绑定微信小程序',
                'value' => false,
            ],
            $this->inputField('wechat_mini_app_id', '微信小程序 AppID'),
            $this->passwordField('wechat_mini_app_secret', '微信小程序 AppSecret'),
            $this->inputField('wechat_mini_launch_path', '微信小程序支付承接路径'),
            [
                'type' => 'select',
                'field' => 'wechat_default_jsapi_product',
                'title' => '微信内默认 JSAPI 产品',
                'value' => 'mp',
                'options' => [
                    ['label' => '公众号', 'value' => 'mp'],
                    ['label' => '小程序', 'value' => 'mini'],
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
            $this->enabledProductsField(),
            $this->inputField('api_base_url', '自定义 HTTPS YOP 网关（留空使用官方生产网关）'),
        ];
    }

    /**
     * 声明当前订单的支付身份需求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>|null 缺少身份时的授权要求，已有身份或无需授权时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payType = $this->payType($order);
        $payment = $this->paymentPayload($order);
        $method = strtolower($this->firstText($payment['method'] ?? ''));
        $env = strtolower($this->firstText($order['_env'] ?? 'pc'));
        if (in_array($method, ['qrcode', 'h5', 'jump', 'urlscheme', 'app'], true)) {
            return null;
        }

        if ($payType === 'alipay'
            && $env === 'alipay'
            && in_array(self::PRODUCT_ALIPAY_JSAPI, $this->enabledProducts(), true)) {
            $this->assertAppScope($payment, $this->configText('alipay_oauth_app_id'), ['op_app_id', 'sub_appid']);
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
                    'sandbox' => false,
                ],
                'message' => '易宝支付宝生活号支付需要当前应用作用域的 buyer_id',
            ];
        }

        if ($payType !== 'wxpay') {
            return null;
        }

        if ($this->isMiniIntent($order)) {
            $this->ensureProduct(self::PRODUCT_WXPAY_MINI);
            if (!$this->configBool('bindwxa')) {
                throw new PaymentProductUnavailableException('易宝通道未开启 bindwxa，不能使用微信小程序产品', 40200);
            }
            $this->assertAppScope($payment, $this->configText('wechat_mini_app_id'), ['sub_appid']);
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
                'env_version' => 'release',
                'mini_launch_type' => $env === 'wechat' ? 'url_link' : 'url_scheme',
                'message' => '易宝微信小程序支付需要当前小程序作用域的 mini_openid',
            ];
        }

        if ($env !== 'wechat' || !in_array(self::PRODUCT_WXPAY_MP, $this->enabledProducts(), true)) {
            return null;
        }
        $this->assertAppScope($payment, $this->configText('wechat_mp_app_id'), ['sub_appid']);
        if ($this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '') !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => 'mp',
            'channel_product' => self::PRODUCT_WXPAY_MP,
            'auth_type' => 'wechat_oauth',
            'identity_field' => 'openid',
            'app_id' => $this->configText('wechat_mp_app_id'),
            '_app_secret' => $this->configText('wechat_mp_app_secret'),
            'scope' => 'snsapi_base',
            'message' => '易宝微信公众号支付需要当前公众号作用域的 openid',
        ];
    }

    /**
     * 发起支付。
     *
     * 显式 method 优先确定托管、直付或扫码产品；未指定时再根据支付方式、环境和已开通产品选择。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    public function pay(array $order): array
    {
        $payType = $this->payType($order);
        $payment = $this->paymentPayload($order);
        $method = strtolower($this->firstText($payment['method'] ?? ''));
        $env = strtolower($this->firstText($order['_env'] ?? 'pc'));

        if (in_array($method, ['app', 'urlscheme'], true)) {
            return match ($payType) {
                'alipay' => $this->tutelageAlipayAppPay($order),
                'wxpay' => $this->tutelageWechatAppPay($order),
                default => throw new PaymentProductUnavailableException('易宝云闪付不支持托管 APP 产品', 40200),
            };
        }
        if (in_array($method, ['h5', 'jump'], true)) {
            if ($payType !== 'wxpay') {
                throw new PaymentException('易宝当前只有微信支持托管 H5 产品', 40200);
            }
            return $this->tutelageH5Pay($order);
        }
        if ($method === 'qrcode') {
            return $this->scanPay($order);
        }
        if ($method === 'jsapi') {
            return match ($payType) {
                'alipay' => $this->alipayJsapiPay($order),
                'wxpay' => $this->isMiniIntent($order) ? $this->wechatMiniPay($order) : $this->wechatMpPay($order),
                default => throw new PaymentProductUnavailableException('易宝云闪付不支持 JSAPI 产品', 40200),
            };
        }
        if ($payType === 'wxpay' && $this->isMiniIntent($order)) {
            return $this->wechatMiniPay($order);
        }
        if ($method !== 'qrcode' && $payType === 'alipay' && $env === 'alipay'
            && in_array(self::PRODUCT_ALIPAY_JSAPI, $this->enabledProducts(), true)) {
            return $this->alipayJsapiPay($order);
        }
        if (!in_array($method, ['qrcode', 'h5', 'jump', 'urlscheme'], true)
            && $payType === 'wxpay' && $env === 'wechat'
            && in_array(self::PRODUCT_WXPAY_MP, $this->enabledProducts(), true)) {
            return $this->wechatMpPay($order);
        }

        return $this->executeDirectPaymentProduct($order, [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WXPAY_MP,
                ],
                'handler' => fn (): array => $payType === 'alipay'
                    ? $this->alipayJsapiPay($order)
                    : $this->wechatMpPay($order),
            ],
            'h5' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => fn (): array => $this->tutelageH5Pay($order),
            ],
            'jump' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],
                'handler' => fn (): array => $this->tutelageH5Pay($order),
            ],
            'urlscheme' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_APP,
                    'wxpay' => self::PRODUCT_WXPAY_APP,
                ],
                'handler' => fn (): array => $payType === 'alipay'
                    ? $this->tutelageAlipayAppPay($order)
                    : $this->tutelageWechatAppPay($order),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ], '易宝');
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $data = $this->apiGet(self::PATH_QUERY, [
            'parentMerchantNo' => $this->configText('parent_merchant_no'),
            'merchantNo' => $this->merchantNo(),
            'orderId' => $payNo,
        ], '查单');
        $this->assertApiCode($data, [self::API_CODE_OPERATION_SUCCESS], '查单');
        $this->assertResponseIdentity($data, $payNo, $this->orderAmount($order, false), true);

        $channelStatus = strtoupper($this->requiredText($data['status'] ?? '', '易宝查单响应缺少 status'));
        $status = match ($channelStatus) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'FAIL', 'FAILED', 'TIME_OUT' => PaymentPluginStatusConstant::FAILED,
            'CLOSE', 'CLOSED' => PaymentPluginStatusConstant::CLOSED,
            'PROCESSING' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
        $tradeNo = $this->firstText($data['uniqueOrderNo'] ?? '', $order['chan_trade_no'] ?? '');
        if ($status === PaymentPluginStatusConstant::SUCCESS && $tradeNo === '') {
            throw new PaymentException('易宝成功查单响应缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }
        $paidAmount = null;
        if ($status === PaymentPluginStatusConstant::SUCCESS) {
            if (!array_key_exists('orderAmount', $data)) {
                throw new PaymentException('易宝成功查单响应缺少订单金额', 40200, ['pay_no' => $payNo]);
            }
            $paidAmount = $this->yuanToCents($data['orderAmount'], '易宝查单订单金额');
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => $channelStatus,
            'message' => $this->firstText($data['message'] ?? '', $channelStatus),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->firstText($data['paySuccessDate'] ?? '')
                : null,
        ];
    }

    /**
     * 关闭支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     *
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $data = $this->apiPost(self::PATH_CLOSE, [
            'parentMerchantNo' => $this->configText('parent_merchant_no'),
            'merchantNo' => $this->merchantNo(),
            'orderId' => $payNo,
        ], '关单');
        $this->assertApiCode($data, [self::API_CODE_PREPAY_SUCCESS, self::API_CODE_OPERATION_SUCCESS], '关单');
        $this->assertResponseIdentity($data, $payNo, null, false);

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => $payNo,
            'message' => '易宝关单成功',
            'chan_order_no' => $payNo,
            'chan_trade_no' => $this->firstText($data['uniqueOrderNo'] ?? '', $order['chan_trade_no'] ?? ''),
            'channel_status' => $this->firstText($data['status'] ?? '', 'CLOSED'),
        ];
    }

    /**
     * 发起退款。
     *
     * 响应必须匹配本地退款单号和分金额，未知退款状态按结果不确定处理。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     *
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $refundNo = $this->requiredText($order['refund_no'] ?? '', '易宝退款缺少退款单号');
        $refundAmount = $this->positiveCents($order['refund_amount'] ?? null, '易宝退款金额必须是正整数分');
        $totalAmount = $this->orderAmount($order, false);
        if ($totalAmount !== null && $refundAmount > $totalAmount) {
            throw new PaymentDefinitiveException('易宝退款金额不能大于原支付金额', 40200);
        }

        $data = $this->apiPost(self::PATH_REFUND, [
            'parentMerchantNo' => $this->configText('parent_merchant_no'),
            'merchantNo' => $this->merchantNo(),
            'orderId' => $payNo,
            'refundRequestId' => $refundNo,
            'refundAmount' => FormatHelper::amount($refundAmount),
        ], '退款');
        $this->assertApiCode($data, [self::API_CODE_OPERATION_SUCCESS], '退款');
        $this->assertResponseIdentity($data, $payNo, null, false);

        $responseRefundNo = $this->requiredText(
            $data['uniqueRefundNo'] ?? '',
            '易宝退款响应缺少退款单号'
        );
        $responseRequestNo = $this->requiredText($data['refundRequestId'] ?? '', '易宝退款响应缺少商户退款单号');
        if (!hash_equals($refundNo, $responseRequestNo)) {
            throw new PaymentUncertainException('易宝退款响应商户退款单号不一致', 40200, ['refund_no' => $refundNo]);
        }
        $responseAmount = $this->yuanToCents($data['refundAmount'] ?? null, '易宝退款响应金额');
        if ($responseAmount !== $refundAmount) {
            throw new PaymentException('易宝退款响应金额不一致', 40200, ['refund_no' => $refundNo]);
        }

        $channelStatus = strtoupper($this->requiredText($data['status'] ?? '', '易宝退款响应缺少 status'));
        return match ($channelStatus) {
            'SUCCESS' => [
                'status' => PaymentPluginStatusConstant::SUCCESS,
                'refund_no' => $refundNo,
                'pay_no' => $payNo,
                'message' => '易宝退款成功',
                'chan_refund_no' => $responseRefundNo,
                'refund_amount' => $responseAmount,
                'channel_status' => $channelStatus,
            ],
            'PROCESSING' => [
                'status' => PaymentPluginStatusConstant::PENDING,
                'refund_no' => $refundNo,
                'pay_no' => $payNo,
                'message' => '易宝退款处理中',
                'chan_refund_no' => $responseRefundNo,
                'refund_amount' => $responseAmount,
                'channel_status' => $channelStatus,
            ],
            'FAILED', 'FAIL', 'CANCEL', 'SUSPEND' => throw new PaymentDefinitiveException('易宝退款失败', 40200, [
                'refund_no' => $refundNo,
                'refund_status' => $channelStatus,
            ]),
            default => throw new PaymentUncertainException('易宝退款返回未知状态', 40200, [
                'refund_no' => $refundNo,
                'refund_status' => $channelStatus,
            ]),
        };
    }

    /**
     * 解析并校验支付通知。
     *
     * 先校验应用标识，再由 SDK 验签解密 response，随后核对发起方/收款商户、币种和必要业务字段。
     *
     * @param Request $request 支付通知请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $response = trim((string) ($request->post('response') ?? ''));
        $customerIdentification = trim((string) ($request->post('customerIdentification') ?? ''));
        if ($response === '' || $customerIdentification === '') {
            throw new PaymentException('易宝回调缺少 response 或 customerIdentification', 40200);
        }
        if (!hash_equals($this->configText('app_key'), $customerIdentification)) {
            throw new PaymentException('易宝回调应用标识不匹配', 40200);
        }

        try {
            $data = $this->client()->notifyDecrypt($response);
        } catch (YeepaySdkException $e) {
            throw new PaymentException('易宝回调密文或签名无效', 40200, ['channel_error_code' => 'INVALID_NOTIFY']);
        }

        $parentMerchantNo = $this->requiredText($data['parentMerchantNo'] ?? '', '易宝回调缺少发起方商户编号');
        $merchantNo = $this->requiredText($data['merchantNo'] ?? '', '易宝回调缺少收款商户编号');
        if (!hash_equals($this->configText('parent_merchant_no'), $parentMerchantNo)
            || !hash_equals($this->merchantNo(), $merchantNo)) {
            throw new PaymentException('易宝回调商户编号不匹配', 40200);
        }

        $payNo = $this->requiredText($data['orderId'] ?? '', '易宝回调缺少订单号');
        $paidAmount = $this->yuanToCents($data['orderAmount'] ?? null, '易宝回调订单金额');
        $currency = strtoupper($this->firstText($data['currency'] ?? '', $data['currencyCode'] ?? '', 'CNY'));
        if ($currency !== 'CNY') {
            throw new PaymentException('易宝回调币种不是 CNY', 40200, ['pay_no' => $payNo]);
        }
        $channelTradeNo = $this->requiredText($data['uniqueOrderNo'] ?? '', '易宝回调缺少渠道交易号');
        $channelStatus = strtoupper($this->requiredText($data['status'] ?? '', '易宝回调缺少支付状态'));
        $status = match ($channelStatus) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'FAIL', 'FAILED', 'TIME_OUT', 'CLOSE', 'CLOSED' => PaymentPluginStatusConstant::FAILED,
            'PROCESSING' => PaymentPluginStatusConstant::PENDING,
            default => throw new PaymentException('易宝回调支付状态不受支持', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $channelStatus,
            ]),
        };

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'message' => $channelStatus,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $channelStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->firstText($data['paySuccessDate'] ?? '')
                : null,
        ];
    }

    /**
     * 返回渠道要求的支付通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回渠道要求的支付通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function notifyFail(): string|Response
    {
        return 'FAIL';
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function scanPay(array $order): array
    {
        $payType = $this->payType($order);
        [$product, $channel] = match ($payType) {
            'alipay' => [self::PRODUCT_ALIPAY_SCAN, 'ALIPAY'],
            'wxpay' => [self::PRODUCT_WXPAY_SCAN, 'WECHAT'],
            'bank' => [self::PRODUCT_BANK_SCAN, 'UNIONPAY'],
            default => throw new PaymentException('易宝不支持当前扫码支付方式', 40200),
        };
        $data = $this->prePay($order, $product, 'USER_SCAN', $channel);
        $qrcode = $this->requiredText($data['prePayTn'] ?? '', '易宝扫码下单未返回二维码内容');

        return $this->payResult($order, $product, 'qrcode', ['qrcode' => $qrcode], $data, 'aggpay.pre-pay');
    }

    /**
     * 发起支付宝 JSAPI 支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function alipayJsapiPay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $buyerId = $this->requiredText($payment['buyer_id'] ?? '', '易宝支付宝 JSAPI 缺少 buyer_id');
        $appId = $this->configText('alipay_oauth_app_id');
        $this->assertAppScope($payment, $appId, ['op_app_id', 'sub_appid']);
        $data = $this->prePay($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY_LIFE', 'ALIPAY', $appId, $buyerId);
        $tradeNo = $this->requiredText($data['prePayTn'] ?? '', '易宝支付宝 JSAPI 未返回交易号');

        return $this->payResult(
            $order,
            self::PRODUCT_ALIPAY_JSAPI,
            'jsapi',
            ['tradeNO' => $tradeNo],
            $data,
            'aggpay.pre-pay'
        );
    }

    /**
     * 发起微信公众号支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function wechatMpPay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['mini_openid'] ?? '') !== '') {
            throw new PaymentException('易宝微信公众号产品不能使用 mini_openid', 40200);
        }
        $openid = $this->requiredText(
            $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? ''),
            '易宝微信公众号支付缺少 openid'
        );
        $appId = $this->configText('wechat_mp_app_id');
        $this->assertAppScope($payment, $appId, ['sub_appid']);
        $data = $this->prePay($order, self::PRODUCT_WXPAY_MP, 'WECHAT_OFFIACCOUNT', 'WECHAT', $appId, $openid);
        $params = $this->jsonObject($data['prePayTn'] ?? '', '易宝微信公众号支付参数');

        return $this->payResult($order, self::PRODUCT_WXPAY_MP, 'jsapi', $params, $data, 'aggpay.pre-pay');
    }

    /**
     * 发起微信小程序支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function wechatMiniPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_WXPAY_MINI);
        if (!$this->configBool('bindwxa')) {
            throw new PaymentProductUnavailableException('易宝通道未开启 bindwxa，不能使用微信小程序产品', 40200);
        }
        $payment = $this->paymentPayload($order);
        $openid = $this->requiredText($payment['mini_openid'] ?? '', '易宝微信小程序支付缺少 mini_openid');
        if ($this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '') !== '') {
            throw new PaymentException('易宝微信小程序产品不能使用公众号 openid', 40200);
        }
        $appId = $this->configText('wechat_mini_app_id');
        $this->assertAppScope($payment, $appId, ['sub_appid']);
        $data = $this->prePay($order, self::PRODUCT_WXPAY_MINI, 'MINI_PROGRAM', 'WECHAT', $appId, $openid);
        $requestPayment = $this->jsonObject($data['prePayTn'] ?? '', '易宝微信小程序支付参数');
        foreach (['timeStamp', 'nonceStr', 'package', 'signType', 'paySign'] as $field) {
            $this->requiredText($requestPayment[$field] ?? '', '易宝微信小程序支付参数缺少 ' . $field);
        }

        return $this->payResult($order, self::PRODUCT_WXPAY_MINI, 'page', [
            '_page' => 'wechatMini',
            'description' => '易宝微信小程序支付参数已生成，调用方必须在小程序容器内调用 wx.requestPayment',
            'launch_type' => 'mini_program_request_payment',
            'app_id' => $appId,
            'request_payment' => $requestPayment,
        ], $data, 'aggpay.pre-pay');
    }

    /**
     * 发起易宝托管 H5 支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function tutelageH5Pay(array $order): array
    {
        $data = $this->tutelagePay($order, self::PRODUCT_WXPAY_H5, 'H5_PAY', 'WECHAT');
        $url = $this->safeUrl($data['prePayTn'] ?? '', ['https'], '易宝微信托管 H5 未返回 HTTPS 地址');

        return $this->payResult($order, self::PRODUCT_WXPAY_H5, 'jump', ['url' => $url], $data, 'aggpay.tutelage.pre-pay');
    }

    /**
     * 发起易宝托管支付宝 APP 支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function tutelageAlipayAppPay(array $order): array
    {
        $data = $this->tutelagePay($order, self::PRODUCT_ALIPAY_APP, 'SDK_PAY', 'ALIPAY', [
            'returnSchema' => 'ALIPAYS',
        ]);
        $url = $this->safeUrl($data['prePayTn'] ?? '', ['alipays', 'https'], '易宝支付宝托管 SDK 未返回可承接地址');

        return $this->payResult($order, self::PRODUCT_ALIPAY_APP, 'urlscheme', ['urlscheme' => $url], $data, 'aggpay.tutelage.pre-pay');
    }

    /**
     * 发起易宝托管微信 APP 支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function tutelageWechatAppPay(array $order): array
    {
        $data = $this->tutelagePay($order, self::PRODUCT_WXPAY_APP, 'SDK_PAY', 'WECHAT');
        $appId = $this->requiredText($data['appId'] ?? '', '易宝微信托管 SDK 未返回 appId');
        $miniProgramId = $this->requiredText($data['miniProgramOrgId'] ?? '', '易宝微信托管 SDK 未返回 miniProgramOrgId');
        $path = $this->requiredText($data['miniProgramPath'] ?? '', '易宝微信托管 SDK 未返回 miniProgramPath');

        return $this->payResult($order, self::PRODUCT_WXPAY_APP, 'page', [
            '_page' => 'page',
            'description' => '该产品必须由商户原生 APP 使用微信 OpenSDK 拉起易宝托管小程序',
            'launch_type' => 'wechat_open_sdk_mini_program',
            'app_id' => $appId,
            'mini_program_id' => $miniProgramId,
            'path' => $path,
        ], $data, 'aggpay.tutelage.pre-pay');
    }

    /**
     * 发起易宝托管支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 易宝托管产品代码
     * @param string $payWay 易宝支付产品类型
     * @param string $channel 易宝渠道代码
     * @param array<string, mixed> $extra 产品专用请求字段
     *
     * @return array<string, mixed> 已校验身份的托管预下单响应
     */
    private function tutelagePay(array $order, string $product, string $payWay, string $channel, array $extra = []): array
    {
        $this->ensureProduct($product);
        $params = $this->baseOrder($order, $channel) + [
            'payWay' => $payWay,
            'channel' => $channel,
        ] + $extra;
        $data = $this->apiPost(self::PATH_TUTELAGE_PREPAY, $params, '托管预下单');
        $this->assertApiCode($data, [self::API_CODE_PREPAY_SUCCESS], '托管预下单');
        $this->assertResponseIdentity($data, $this->orderPayNo($order), (int) $order['amount'], false);

        return $data;
    }

    /**
     * 发起渠道预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 易宝直付产品代码
     * @param string $payWay 易宝支付产品类型
     * @param string $channel 易宝渠道代码
     * @param string $appId 支付身份所属应用 AppID
     * @param string $userId 当前应用作用域的用户标识
     *
     * @return array<string, mixed> 已校验身份的聚合预下单响应
     */
    private function prePay(
        array $order,
        string $product,
        string $payWay,
        string $channel,
        string $appId = '',
        string $userId = ''
    ): array {
        $this->ensureProduct($product);
        $params = $this->baseOrder($order, $channel) + [
            'payWay' => $payWay,
            'channel' => $channel,
        ];
        if ($appId !== '') {
            $params['appId'] = $appId;
        }
        if ($userId !== '') {
            $params['userId'] = $userId;
        }
        $data = $this->apiPost(self::PATH_PREPAY, $params, '聚合预下单');
        $this->assertApiCode($data, [self::API_CODE_PREPAY_SUCCESS], '聚合预下单');
        $this->assertResponseIdentity($data, $this->orderPayNo($order), (int) $order['amount'], false);

        return $data;
    }

    /**
     * 构建渠道基础订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $channel 易宝渠道代码
     *
     * @return array<string, mixed> 上游基础订单参数
     */
    private function baseOrder(array $order, string $channel): array
    {
        $amount = $this->positiveCents($order['amount'] ?? null, '易宝支付金额必须是正整数分');
        $params = [
            'parentMerchantNo' => $this->configText('parent_merchant_no'),
            'merchantNo' => $this->merchantNo(),
            'orderId' => $this->orderPayNo($order),
            'orderAmount' => FormatHelper::amount($amount),
            'goodsName' => mb_strcut($this->requiredText($order['subject'] ?? '', '易宝订单标题不能为空'), 0, 85, 'UTF-8'),
            'notifyUrl' => $this->safeUrl($order['callback_url'] ?? '', ['https', 'http'], '易宝通知地址无效'),
            'redirectUrl' => $this->safeUrl($order['return_url'] ?? '', ['https', 'http'], '易宝同步返回地址无效'),
            'userIp' => $this->requiredText($order['client_ip'] ?? '', '易宝下单缺少客户端 IP'),
        ];
        if ($channel === 'WECHAT') {
            $params['scene'] = $this->configText('wechat_scene') ?: 'ONLINE';
        } elseif ($channel === 'ALIPAY') {
            $params['scene'] = $this->configText('alipay_scene') ?: 'OFFLINE';
        }

        return $params;
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 易宝产品代码
     * @param string $payPage 收银台承接页类型
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $response 上游响应
     * @param string $action 支付动作标识
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function payResult(
        array $order,
        string $product,
        string $payPage,
        array $payParams,
        array $response,
        string $action
    ): array {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $payPage,
            'pay_type' => $this->payType($order),
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => $this->orderPayNo($order),
            'chan_trade_no' => $this->firstText($response['uniqueOrderNo'] ?? ''),
        ]);
    }

    /**
     * 调用渠道 POST 接口。
     *
     * POST 用于会改变渠道状态的操作，请求异常无法证明未受理，因此转换为结果不确定异常。
     *
     * @param string $path YOP 接口路径
     * @param array<string, mixed> $params 请求参数
     * @param string $operation 用于异常提示的业务动作
     *
     * @return array<string, mixed> 已验签的渠道响应
     */
    private function apiPost(string $path, array $params, string $operation): array
    {
        try {
            return $this->client()->post($path, $params);
        } catch (YeepaySdkException $e) {
            throw new PaymentUncertainException('易宝' . $operation . '失败：' . $e->getMessage(), 40200, [
                'channel_error_code' => 'YOP_REQUEST_FAILED',
                'operation' => $operation,
            ]);
        }
    }

    /**
     * 调用渠道 GET 接口。
     *
     * @param string $path YOP 接口路径
     * @param array<string, mixed> $params 请求参数
     * @param string $operation 用于异常提示的业务动作
     *
     * @return array<string, mixed> 已验签的渠道响应
     */
    private function apiGet(string $path, array $params, string $operation): array
    {
        try {
            return $this->client()->get($path, $params);
        } catch (YeepaySdkException $e) {
            throw new PaymentException('易宝' . $operation . '失败：' . $e->getMessage(), 40200, [
                'channel_error_code' => 'YOP_REQUEST_FAILED',
                'operation' => $operation,
            ]);
        }
    }

    /**
     * 校验渠道业务响应码。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param array<int, string> $successCodes 当前操作允许的成功码
     * @param string $operation 用于异常提示的业务动作
     */
    private function assertApiCode(array $data, array $successCodes, string $operation): void
    {
        $code = trim((string) ($data['code'] ?? ''));
        if (!in_array($code, $successCodes, true)) {
            $message = preg_replace('/\s+/', ' ', trim((string) ($data['message'] ?? '渠道返回失败'))) ?: '渠道返回失败';
            throw new PaymentDefinitiveException('易宝' . $operation . '失败：[' . $code . ']' . mb_strcut($message, 0, 200, 'UTF-8'), 40200, [
                'channel_error_code' => $code !== '' ? $code : 'UNKNOWN',
                'operation' => $operation,
            ]);
        }
    }

    /**
     * 校验渠道响应身份。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $expectedPayNo 本地预期支付单号
     * @param int|null $expectedAmount 本地预期分金额；null 表示不校验金额
     * @param bool $requireOrderIdentity 是否要求响应必须携带订单号
     */
    private function assertResponseIdentity(
        array $data,
        string $expectedPayNo,
        ?int $expectedAmount,
        bool $requireOrderIdentity
    ): void {
        $orderId = trim((string) ($data['orderId'] ?? ''));
        if ($requireOrderIdentity && $orderId === '') {
            throw new PaymentException('易宝响应缺少订单号', 40200, ['pay_no' => $expectedPayNo]);
        }
        if ($orderId !== '' && !hash_equals($expectedPayNo, $orderId)) {
            throw new PaymentException('易宝响应订单号不一致', 40200, ['pay_no' => $expectedPayNo]);
        }
        $parentMerchantNo = trim((string) ($data['parentMerchantNo'] ?? ''));
        if ($parentMerchantNo !== '' && !hash_equals($this->configText('parent_merchant_no'), $parentMerchantNo)) {
            throw new PaymentException('易宝响应发起方商户编号不一致', 40200, ['pay_no' => $expectedPayNo]);
        }
        $merchantNo = trim((string) ($data['merchantNo'] ?? ''));
        if ($merchantNo !== '' && !hash_equals($this->merchantNo(), $merchantNo)) {
            throw new PaymentException('易宝响应收款商户编号不一致', 40200, ['pay_no' => $expectedPayNo]);
        }
        if ($expectedAmount !== null && array_key_exists('orderAmount', $data)
            && $this->yuanToCents($data['orderAmount'], '易宝响应订单金额') !== $expectedAmount) {
            throw new PaymentException('易宝响应订单金额不一致', 40200, ['pay_no' => $expectedPayNo]);
        }
        $currency = strtoupper($this->firstText($data['currency'] ?? '', $data['currencyCode'] ?? ''));
        if ($currency !== '' && $currency !== 'CNY') {
            throw new PaymentException('易宝响应币种不是 CNY', 40200, ['pay_no' => $expectedPayNo]);
        }
    }

    /**
     * 获取按应用标识、商户私钥和平台公钥初始化的 YOP 客户端。
     */
    private function client(): YeepayYopClient
    {
        if ($this->client === null) {
            $this->client = new YeepayYopClient([
                'app_key' => $this->configText('app_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 校验密钥格式、HTTPS 网关、业务场景及已开通产品的必需配置。
     */
    private function validateConfiguration(): void
    {
        foreach (['app_key', 'merchant_private_key', 'platform_public_key', 'parent_merchant_no'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException('易宝配置缺少 ' . $field, 40200);
            }
        }
        if (openssl_pkey_get_private($this->pemPrivateKey($this->configText('merchant_private_key'))) === false) {
            throw new PaymentException('易宝商户 RSA 私钥格式错误', 40200);
        }
        if (openssl_pkey_get_public($this->pemPublicKey($this->configText('platform_public_key'))) === false) {
            throw new PaymentException('易宝平台 RSA 公钥格式错误', 40200);
        }
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, array_keys(self::PRODUCT_OPTIONS)) !== []) {
            throw new PaymentException('易宝已开通产品配置为空或包含未知产品', 40200);
        }
        $apiBaseUrl = $this->configText('api_base_url');
        if ($apiBaseUrl !== '' && (!str_starts_with(strtolower($apiBaseUrl), 'https://')
            || filter_var($apiBaseUrl, FILTER_VALIDATE_URL) === false)) {
            throw new PaymentException('易宝自定义 YOP 网关必须是有效 HTTPS 地址', 40200);
        }
        $wechatScene = $this->configText('wechat_scene') ?: 'ONLINE';
        if (!in_array($wechatScene, ['ONLINE', 'OFFLINE'], true)) {
            throw new PaymentException('易宝微信支付场景配置无效', 40200);
        }
        $alipayScene = $this->configText('alipay_scene') ?: 'OFFLINE';
        if (!in_array($alipayScene, ['OFFLINE', 'LARGE', 'REGISTRATION'], true)) {
            throw new PaymentException('易宝支付宝支付场景配置无效', 40200);
        }
        if (in_array(self::PRODUCT_WXPAY_MP, $enabled, true)) {
            $this->requireConfigFields(['wechat_mp_app_id', 'wechat_mp_app_secret'], '微信公众号产品');
        }
        if (in_array(self::PRODUCT_WXPAY_MINI, $enabled, true)) {
            if (!$this->configBool('bindwxa')) {
                throw new PaymentException('易宝微信小程序产品只有 bindwxa=true 时才能启用', 40200);
            }
            $this->requireConfigFields(
                ['wechat_mini_app_id', 'wechat_mini_app_secret', 'wechat_mini_launch_path'],
                '微信小程序产品'
            );
        }
        if (in_array(self::PRODUCT_ALIPAY_JSAPI, $enabled, true)) {
            $this->requireConfigFields(
                ['alipay_oauth_app_id', 'alipay_oauth_private_key', 'alipay_oauth_public_key'],
                '支付宝 JSAPI 身份流程'
            );
        }
    }

    /**
     * 校验必填配置项。
     *
     * @param array<int, string> $fields 必填配置键
     * @param string $scene 用于异常提示的产品或身份场景
     */
    private function requireConfigFields(array $fields, string $scene): void
    {
        foreach ($fields as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException('易宝' . $scene . '缺少 ' . $field, 40200);
            }
        }
    }

    private function merchantNo(): string
    {
        return $this->configText('merchant_no') ?: $this->configText('parent_merchant_no');
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前易宝通道未开通该支付产品', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
                'product' => $product,
            ]);
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
        if (!is_array($products)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $products
        ))));
    }

    /**
     * 判断是否请求小程序支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function isMiniIntent(array $order): bool
    {
        if ($this->payType($order) !== 'wxpay') {
            return false;
        }
        $payment = $this->paymentPayload($order);
        $method = strtolower($this->firstText($payment['method'] ?? ''));
        if ($method !== '') {
            return $method === 'mini';
        }
        if ($this->firstText($payment['mini_openid'] ?? '', $payment['mini_code'] ?? '', $payment['wx_login_code'] ?? '') !== '') {
            return true;
        }

        return strtolower($this->configText('wechat_default_jsapi_product')) === 'mini'
            && strtolower($this->firstText($order['_env'] ?? '')) === 'wechat';
    }

    /**
     * 校验支付身份所属应用。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $expectedAppId 当前产品配置的应用 AppID
     * @param array<int, string> $fields 订单中可能携带应用标识的字段
     */
    private function assertAppScope(array $payment, string $expectedAppId, array $fields): void
    {
        foreach ($fields as $field) {
            $actual = trim((string) ($payment[$field] ?? ''));
            if ($actual !== '' && ($expectedAppId === '' || !hash_equals($expectedAppId, $actual))) {
                throw new PaymentException('易宝支付身份 AppID 与当前产品配置不一致', 40200, ['field' => $field]);
            }
        }
    }

    /**
     * 获取支付方式编码。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function payType(array $order): string
    {
        $payType = strtolower($this->requiredText($order['pay_type_code'] ?? '', '易宝下单缺少支付方式'));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentException('易宝不支持当前支付方式', 40200, ['pay_type' => $payType]);
        }

        return $payType;
    }

    /**
     * 获取平台支付单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     */
    private function orderPayNo(array $order): string
    {
        return $this->requiredText(
            $order['pay_no'] ?? '',
            '易宝操作缺少支付单号'
        );
    }

    /**
     * 获取订单金额。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @param bool $required 缺少金额时是否抛出异常
     */
    private function orderAmount(array $order, bool $required = true): ?int
    {
        if (array_key_exists('amount', $order) && $order['amount'] !== null && $order['amount'] !== '') {
            return $this->positiveCents($order['amount'], '易宝订单金额必须是正整数分');
        }
        if ($required) {
            throw new PaymentException('易宝操作缺少订单金额', 40200);
        }

        return null;
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

    private function positiveCents(mixed $value, string $message): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return (int) $value;
    }

    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '不是合法的两位小数元金额', 40200);
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 2, '0');
        $major = (int) $matches[1];
        if ($major > intdiv(PHP_INT_MAX - (int) $fraction, 100)) {
            throw new PaymentException($field . '超出整数分范围', 40200);
        }

        return $major * 100 + (int) $fraction;
    }

    /**
     * 解析 JSON 对象。
     *
     * @param mixed $value JSON 字符串或已解析对象
     * @param string $field 用于异常提示的字段名称
     *
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $field): array
    {
        if (is_array($value) && !array_is_list($value)) {
            return $value;
        }
        $decoded = json_decode(trim((string) $value), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new PaymentException($field . '不是合法 JSON 对象', 40200);
        }

        return $decoded;
    }

    /**
     * 校验并返回安全跳转地址。
     *
     * @param mixed $value 上游跳转地址原值
     * @param array<int, string> $allowedSchemes 当前产品允许的 URL 协议
     * @param string $message 校验失败提示
     */
    private function safeUrl(mixed $value, array $allowedSchemes, string $message): string
    {
        $url = trim((string) $value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !in_array($scheme, $allowedSchemes, true)) {
            throw new PaymentException($message, 40200);
        }

        return $url;
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentException($message, 40200);
        }

        return $text;
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

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    private function configBool(string $key): bool
    {
        return filter_var($this->getConfig($key, false), FILTER_VALIDATE_BOOL);
    }

    /**
     * 构建已开通产品配置字段。
     *
     * @return array<string, mixed>
     */
    private function enabledProductsField(): array
    {
        $field = $this->directPaymentEnabledProductsField(self::PRODUCT_OPTIONS);
        $field['title'] = '已开通且完成商户授权的产品';
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
        $schema = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => ''];
        if ($required) {
            $schema['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $schema;
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
     * 将无 PEM 头的商户私钥补齐为 OpenSSL 可识别格式。
     *
     * @param string $key 商户私钥文本
     */
    private function pemPrivateKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key) ?? '';
        foreach (['RSA PRIVATE KEY', 'PRIVATE KEY'] as $label) {
            $candidate = "-----BEGIN {$label}-----\n"
                . wordwrap($body, 64, "\n", true)
                . "\n-----END {$label}-----";
            if (openssl_pkey_get_private($candidate) !== false) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * 将无 PEM 头的平台公钥补齐为 OpenSSL 可识别格式。
     *
     * @param string $key 平台公钥文本
     */
    private function pemPublicKey(string $key): string
    {
        if (str_contains($key, 'BEGIN')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key) ?? '';
        foreach (['PUBLIC KEY', 'RSA PUBLIC KEY'] as $label) {
            $candidate = "-----BEGIN {$label}-----\n"
                . wordwrap($body, 64, "\n", true)
                . "\n-----END {$label}-----";
            if (openssl_pkey_get_public($candidate) !== false) {
                return $candidate;
            }
        }

        return '';
    }
}
