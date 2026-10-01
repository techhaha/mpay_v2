<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\huifu\HuifuClient;
use app\common\sdk\huifu\HuifuSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use JsonException;
use support\Request;
use support\Response;

/**
 * 汇付斗拱支付插件。
 *
 * 提供扫码、JSAPI、付款码、托管收银台、快捷支付、网银、查单、关单和退款能力。
 * 插件负责按原支付产品和请求日期执行后续操作，并校验响应商户、流水、金额与通知币种；
 * 微信 T_NATIVE 兼容产品只有在通道明确开通后才允许使用。
 */
class HuifuApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_ALIPAY_HOSTED = 'alipay_hosted';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_WXPAY_HOSTED = 'wxpay_hosted';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_QUICKPAY_PAGE = 'quickpay_page';
    private const PRODUCT_BANK_WEB = 'bank_web';
    private const PRODUCT_ECNY_SCAN = 'ecny_scan';
    private const PRODUCT_BARCODE = 'barcode';

    /**
     * 产品值、后台选项、handler 依赖和 pay_product 的唯一来源。
     *
     * 微信 T_NATIVE 不在斗拱现行聚合正扫 trade_type 枚举中，非公开兼容入口
     * 默认关闭，仅在通道明确开通后启用。
     */
    private const PRODUCT_OPTIONS = [
        self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
        self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
        self::PRODUCT_ALIPAY_HOSTED => '支付宝托管 H5/PC',
        self::PRODUCT_WXPAY_SCAN => '微信扫码（旧 MPAY T_NATIVE，需现行协议证据）',
        self::PRODUCT_WXPAY_JSAPI => '微信公众号 JSAPI',
        self::PRODUCT_WXPAY_MINI => '微信小程序 JSAPI',
        self::PRODUCT_WXPAY_HOSTED => '微信托管 H5/PC',
        self::PRODUCT_BANK_SCAN => '云闪付扫码',
        self::PRODUCT_QUICKPAY_PAGE => '快捷支付页面版',
        self::PRODUCT_BANK_WEB => '网银支付页面版',
        self::PRODUCT_ECNY_SCAN => '数字人民币扫码',
        self::PRODUCT_BARCODE => '付款码支付',
    ];

    private const SCAN_PRODUCTS = [
        self::PRODUCT_ALIPAY_SCAN,
        self::PRODUCT_ALIPAY_JSAPI,
        self::PRODUCT_WXPAY_SCAN,
        self::PRODUCT_WXPAY_JSAPI,
        self::PRODUCT_WXPAY_MINI,
        self::PRODUCT_BANK_SCAN,
        self::PRODUCT_ECNY_SCAN,
        self::PRODUCT_BARCODE,
    ];

    private const HOSTED_PRODUCTS = [
        self::PRODUCT_ALIPAY_HOSTED,
        self::PRODUCT_WXPAY_HOSTED,
    ];

    private const ONLINE_PRODUCTS = [
        self::PRODUCT_QUICKPAY_PAGE,
        self::PRODUCT_BANK_WEB,
    ];

    private ?HuifuClient $client = null;
    private string $notifyAckOrderNo = '';

    /**
     * 插件元信息。
     *
     * 配置表单由 getConfigSchema() 动态生成，以便按通道声明实际开通的斗拱产品。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'huifu_api',
        'name' => '汇付斗拱平台支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '2.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank', 'ecny'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 获取插件配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            $this->inputField('sys_id', '汇付系统号', true),
            $this->inputField('product_id', '汇付产品号', true),
            $this->inputField('sub_merchant_no', '汇付交易商户号（渠道商模式必填）'),
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户私钥',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [['required' => true, 'message' => '商户私钥不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'huifu_public_key',
                'title' => '汇付公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [['required' => true, 'message' => '汇付公钥不能为空']],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通产品',
                'value' => [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_BANK_SCAN],
                'options' => array_map(
                    static fn (string $value, string $label): array => ['label' => $label, 'value' => $value],
                    array_keys(self::PRODUCT_OPTIONS),
                    array_values(self::PRODUCT_OPTIONS)
                ),
                'validate' => [['required' => true, 'message' => '已开通产品不能为空']],
            ],
            $this->inputField('wx_mp_app_id', '微信公众号 AppID'),
            $this->inputField('wx_mp_app_secret', '微信公众号 AppSecret'),
            $this->inputField('wx_mini_app_id', '微信小程序 AppID'),
            $this->inputField('wx_mini_app_secret', '微信小程序 AppSecret'),
            $this->inputField('wx_mini_launch_path', '微信小程序身份续跑路径'),
            $this->inputField('wx_goods_product_id', '微信商品 ID', false, '01001'),
            $this->inputField('alipay_app_id', '支付宝应用 AppID'),
            [
                'type' => 'textarea',
                'field' => 'alipay_app_private_key',
                'title' => '支付宝应用私钥（身份授权）',
                'value' => '',
                'props' => ['rows' => 4],
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_public_key',
                'title' => '支付宝公钥（身份授权）',
                'value' => '',
                'props' => ['rows' => 4],
            ],
            $this->inputField('alipay_mini_launch_path', '支付宝小程序身份续跑路径'),
            $this->inputField('hosted_project_id', '托管收银台项目号'),
            $this->inputField('hosted_project_title', '托管收银台标题', false, 'MPAY 收银台'),
            $this->inputField('quickpay_biz_type', '快捷支付业务种类', false, '100099'),
            $this->inputField('bank_biz_type', '网银支付业务种类', false, '100099'),
            [
                'type' => 'select',
                'field' => 'bank_gate_type',
                'title' => '网银网关类型',
                'value' => '01',
                'options' => [
                    ['label' => '个人网关 B2C', 'value' => '01'],
                    ['label' => '企业网关 B2B', 'value' => '02'],
                ],
            ],
            [
                'type' => 'select',
                'field' => 'bank_card_type',
                'title' => '网银卡类型',
                'value' => 'D',
                'options' => [
                    ['label' => '借记卡', 'value' => 'D'],
                    ['label' => '信用卡', 'value' => 'C'],
                ],
            ],
            $this->inputField('bank_id', '网银指定银行编码'),
            $this->inputField('api_base_url', '自定义 HTTPS 网关地址'),
        ];
    }

    /**
     * 初始化插件。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;
        $this->notifyAckOrderNo = '';

        foreach (['sys_id' => '系统号', 'product_id' => '产品号', 'merchant_private_key' => '商户私钥', 'huifu_public_key' => '汇付公钥'] as $field => $label) {
            if ($this->configText($field) === '') {
                throw new PaymentException('汇付' . $label . '不能为空', 40200);
            }
        }

        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, array_keys(self::PRODUCT_OPTIONS)) !== []) {
            throw new PaymentException('汇付已开通产品配置为空或包含未知产品', 40200, [
                'enabled_products' => $enabled,
                'supported_products' => array_keys(self::PRODUCT_OPTIONS),
            ]);
        }
        if (array_intersect($enabled, self::HOSTED_PRODUCTS) !== [] && $this->configText('hosted_project_id') === '') {
            throw new PaymentException('汇付托管支付必须配置托管收银台项目号', 40200);
        }
        if (in_array(self::PRODUCT_WXPAY_JSAPI, $enabled, true)
            && ($this->configText('wx_mp_app_id') === '' || $this->configText('wx_mp_app_secret') === '')) {
            throw new PaymentException('汇付微信公众号 JSAPI 必须配置公众号 AppID 与 AppSecret', 40200);
        }
        if (in_array(self::PRODUCT_WXPAY_MINI, $enabled, true)
            && ($this->configText('wx_mini_app_id') === '' || $this->configText('wx_mini_app_secret') === '')) {
            throw new PaymentException('汇付微信小程序支付必须配置小程序 AppID 与 AppSecret', 40200);
        }
        if (in_array(self::PRODUCT_ALIPAY_JSAPI, $enabled, true)) {
            foreach (['alipay_app_id' => '支付宝应用 AppID', 'alipay_app_private_key' => '支付宝应用私钥', 'alipay_public_key' => '支付宝公钥'] as $field => $label) {
                if ($this->configText($field) === '') {
                    throw new PaymentException('汇付支付宝 JSAPI 必须配置' . $label, 40200);
                }
            }
        }

        $gateway = $this->configText('api_base_url');
        if ($gateway !== '' && !str_starts_with(strtolower($gateway), 'https://')) {
            throw new PaymentException('汇付自定义网关必须使用 HTTPS', 40200);
        }
    }

    /**
     * 声明当前订单的支付身份需求。
     *
     * 付款码和页面类产品不触发授权；支付宝、公众号与小程序只声明当前应用作用域的身份要求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；无需补充身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($this->firstText($payment['auth_code'] ?? '') !== ''
            || in_array($method, ['qrcode', 'h5', 'web', 'jump', 'urlscheme'], true)) {
            return null;
        }

        if ($payType === 'alipay' && $env === 'alipay'
            && in_array(self::PRODUCT_ALIPAY_JSAPI, $this->enabledProducts(), true)) {
            $this->assertScopedAppId($payment, 'alipay_app_id', $this->configText('alipay_app_id'), '支付宝');
            if ($this->firstText($payment['buyer_id'] ?? '') !== '') {
                return null;
            }

            return [
                'provider' => 'alipay',
                'product' => self::PRODUCT_ALIPAY_JSAPI,
                'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
                'auth_type' => $this->configText('alipay_mini_launch_path') !== '' ? 'alipay_mini' : 'alipay_oauth',
                'identity_field' => 'buyer_id',
                'app_id' => $this->configText('alipay_app_id'),
                'mini_path' => $this->configText('alipay_mini_launch_path'),
                'scope' => 'auth_base',
                '_alipay_config' => [
                    'mode' => 'key',
                    'app_id' => $this->configText('alipay_app_id'),
                    'private_key' => $this->configText('alipay_app_private_key'),
                    'alipay_public_key' => $this->configText('alipay_public_key'),
                    'sandbox' => false,
                ],
                'message' => '汇付支付宝 JSAPI 需要当前支付宝应用作用域的 buyer_id',
            ];
        }

        if ($payType !== 'wxpay' || $env !== 'wechat') {
            return null;
        }

        $mini = $this->isMiniIntent($payment);
        $product = $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_JSAPI;
        if (!in_array($product, $this->enabledProducts(), true)) {
            return null;
        }
        $appId = $mini ? $this->configText('wx_mini_app_id') : $this->configText('wx_mp_app_id');
        $appSecret = $mini ? $this->configText('wx_mini_app_secret') : $this->configText('wx_mp_app_secret');
        $identityField = $mini ? 'mini_openid' : 'sub_openid';
        $identity = $mini
            ? $this->firstText($payment['mini_openid'] ?? '')
            : $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
        $this->assertScopedAppId($payment, $mini ? 'mini_app_id' : 'sub_appid', $appId, $mini ? '微信小程序' : '微信公众号');
        if ($identity !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => $product,
            'channel_product' => $product,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $identityField,
            'identity_aliases' => $mini ? [] : ['openid'],
            'app_id' => $appId,
            '_app_secret' => $appSecret,
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wx_mini_launch_path') : '',
            'env_version' => $mini ? 'release' : '',
            'mini_launch_type' => $mini ? 'url_link' : '',
            'message' => $mini
                ? '汇付微信小程序支付需要当前小程序作用域的 mini_openid'
                : '汇付微信公众号支付需要当前公众号作用域的 sub_openid',
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
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        $mini = $this->isMiniIntent($this->paymentPayload($order));
        $wxProduct = $mini
            ? self::PRODUCT_WXPAY_MINI
            : self::PRODUCT_WXPAY_JSAPI;
        $jsapiHandler = $mini ? 'mini' : 'jsapi';

        return $this->executeDirectPaymentProduct($order, [
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_BARCODE,
                    'wxpay' => self::PRODUCT_BARCODE,
                    'bank' => self::PRODUCT_BARCODE,
                    'ecny' => self::PRODUCT_BARCODE,
                ],
                'handler' => fn (): array => $this->barcodePay($order, (string) ($this->paymentPayload($order)['auth_code'] ?? '')),
            ],
            $jsapiHandler => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI, 'wxpay' => $wxProduct],
                'handler' => function () use ($order, $payType, $wxProduct): array {
                    return match ($payType) {
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'A_JSAPI'),
                        'wxpay' => $this->jsapiPay($order, $wxProduct, $wxProduct === self::PRODUCT_WXPAY_MINI ? 'T_MINIAPP' : 'T_JSAPI'),
                        default => throw new PaymentException('汇付不支持当前 JSAPI 支付方式', 40200),
                    };
                },
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_HOSTED,
                    'wxpay' => self::PRODUCT_WXPAY_HOSTED,
                    'bank' => self::PRODUCT_QUICKPAY_PAGE,
                ],
                'handler' => fn (): array => $payType === 'bank'
                    ? $this->onlinePagePay($order, self::PRODUCT_QUICKPAY_PAGE)
                    : $this->hostedPay($order, $payType),
            ],
            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_HOSTED,
                    'wxpay' => self::PRODUCT_WXPAY_HOSTED,
                    'bank' => self::PRODUCT_BANK_WEB,
                ],
                'handler' => fn (): array => $payType === 'bank'
                    ? $this->onlinePagePay($order, self::PRODUCT_BANK_WEB)
                    : $this->hostedPay($order, $payType),
            ],
            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_HOSTED,
                    'wxpay' => self::PRODUCT_WXPAY_HOSTED,
                    'bank' => self::PRODUCT_BANK_WEB,
                ],
                'handler' => fn (): array => $payType === 'bank'
                    ? $this->onlinePagePay($order, self::PRODUCT_BANK_WEB)
                    : $this->hostedPay($order, $payType),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'ecny' => self::PRODUCT_ECNY_SCAN,
                ],
                'handler' => fn (): array => $this->scanPayByType($order, $payType),
            ],
        ], '汇付');
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        $product = $this->orderProduct($order);
        $payNo = $this->requiredText($order['pay_no'] ?? '', '汇付查单缺少支付单号');
        $requestDate = $this->originalRequestDate($order);

        if (in_array($product, self::HOSTED_PRODUCTS, true)) {
            $path = '/v2/trade/hosting/payment/queryorderinfo';
            $payload = [
                'req_date' => date('Ymd'),
                'req_seq_id' => $this->actionSequence('HQ'),
                'huifu_id' => $this->huifuId(),
                'org_req_date' => $requestDate,
                'org_req_seq_id' => $payNo,
            ];
        } elseif (in_array($product, self::ONLINE_PRODUCTS, true)) {
            $path = '/v2/trade/onlinepayment/query';
            $payload = [
                'huifu_id' => $this->huifuId(),
                'org_req_date' => $requestDate,
                'org_req_seq_id' => $payNo,
            ];
        } else {
            $path = '/v3/trade/payment/scanpay/query';
            $payload = [
                'huifu_id' => $this->huifuId(),
                'org_req_date' => $requestDate,
                'org_req_seq_id' => $payNo,
            ];
        }

        $data = $this->upstreamRequest($path, $payload, '查单');
        $this->assertBusinessCode($data, '查单', ['00000000']);
        $this->assertMerchant($data, '查单');
        $this->assertOriginalOrder($data, $payNo, '查单');
        $this->assertOrderAmount($data, (int) ($order['amount'] ?? 0), '查单');

        $channelStatus = $this->requiredText($data['trans_stat'] ?? '', '汇付查单未返回交易状态');
        $status = $this->tradeStatus($channelStatus);
        $paidAmount = $status === PaymentPluginStatusConstant::SUCCESS
            ? $this->amountToCents($data['trans_amt'] ?? null, '查单交易金额')
            : null;

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $this->firstText($data['org_hf_seq_id'] ?? '', $data['hf_seq_id'] ?? '', $order['chan_trade_no'] ?? ''),
            'channel_status' => $channelStatus,
            'paid_at' => $this->normalizeTime($data['end_time'] ?? ''),
            'message' => $this->firstText($data['resp_desc'] ?? '', $channelStatus),
            'raw_data' => $this->diagnosticSummary($data),
        ];
    }

    /**
     * 关闭支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $product = $this->orderProduct($order);
        if (in_array($product, self::ONLINE_PRODUCTS, true)) {
            throw new UnsupportedPaymentOperationException('汇付快捷/网银现行公开协议未提供关单接口', 40200, ['pay_product' => $product]);
        }

        $payNo = $this->requiredText($order['pay_no'] ?? '', '汇付关单缺少支付单号');
        $hosted = in_array($product, self::HOSTED_PRODUCTS, true);
        $path = $hosted
            ? '/v2/trade/hosting/payment/close'
            : '/v2/trade/payment/scanpay/close';
        $data = $this->upstreamRequest($path, [
            'req_date' => date('Ymd'),
            'req_seq_id' => $this->actionSequence('CL'),
            'huifu_id' => $this->huifuId(),
            'org_req_date' => $this->originalRequestDate($order),
            'org_req_seq_id' => $payNo,
        ], '关单', true);
        $this->assertMerchant($data, '关单');
        $this->assertOriginalOrder($data, $payNo, '关单');

        $code = (string) ($data['resp_code'] ?? '');
        if (in_array($code, $hosted ? ['30000001'] : ['23000000'], true)) {
            $query = $this->query($order);
            if ((string) ($query['status'] ?? '') === PaymentPluginStatusConstant::SUCCESS) {
                throw new PaymentException('汇付原订单已支付成功，不能关单', 40200, $this->diagnosticSummary($data));
            }
            if (in_array((string) ($query['status'] ?? ''), [PaymentPluginStatusConstant::FAILED, PaymentPluginStatusConstant::CLOSED], true)) {
                return $this->closeResult($order, PaymentPluginStatusConstant::CLOSED, '汇付订单已处于不可支付终态');
            }

            return $this->closeResult($order, PaymentPluginStatusConstant::PENDING, '汇付原订单状态仍在处理中');
        }

        $this->assertBusinessCode($data, '关单', ['00000000', '00000100']);
        $status = $this->firstText($data['trans_stat'] ?? '');
        if ($status === 'S' || ($status === '' && $code === '00000000')) {
            return $this->closeResult($order, PaymentPluginStatusConstant::CLOSED, '关单成功');
        }
        if (in_array($status, ['P', 'I'], true) || $code === '00000100') {
            return $this->closeResult($order, PaymentPluginStatusConstant::PENDING, '汇付关单处理中');
        }

        throw new PaymentException('汇付关单失败：' . $this->firstText($data['resp_desc'] ?? '', $status), 40200, $this->diagnosticSummary($data));
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $product = $this->orderProduct($order);
        $payNo = $this->requiredText($order['pay_no'] ?? '', '汇付退款缺少支付单号');
        $refundNo = $this->requiredText($order['refund_no'] ?? '', '汇付退款缺少退款单号');
        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        if ($refundAmount <= 0) {
            throw new PaymentDefinitiveException('汇付退款金额必须大于 0 分', 40200);
        }

        $path = match (true) {
            in_array($product, self::HOSTED_PRODUCTS, true) => '/v2/trade/hosting/payment/htRefund',
            in_array($product, self::ONLINE_PRODUCTS, true) => '/v2/trade/onlinepayment/refund',
            default => '/v3/trade/payment/scanpay/refund',
        };
        $data = $this->upstreamRequest($path, [
            'req_date' => date('Ymd'),
            'req_seq_id' => $refundNo,
            'huifu_id' => $this->huifuId(),
            'ord_amt' => FormatHelper::amount($refundAmount),
            'org_req_date' => $this->originalRequestDate($order),
            'org_req_seq_id' => $payNo,
            'refund_desc' => mb_strcut((string) ($order['refund_reason'] ?? '退款'), 0, 120, 'UTF-8'),
        ], '退款', true);
        $this->assertMerchant($data, '退款');
        $this->assertResponseSequence($data, $refundNo, '退款');
        $this->assertOriginalOrder($data, $payNo, '退款');

        $code = (string) ($data['resp_code'] ?? '');
        if ($code === '20000000') {
            return $this->refundResult($order, $data, $refundAmount, PaymentPluginStatusConstant::PENDING, '重复退款请求，等待按原退款单号查询');
        }
        $this->assertBusinessCode($data, '退款', ['00000000', '00000100']);
        $channelStatus = $this->firstText($data['trans_stat'] ?? '');
        if ($channelStatus === 'F') {
            throw new PaymentDefinitiveException('汇付退款失败：' . $this->firstText($data['resp_desc'] ?? '', '渠道返回失败'), 40200, $this->diagnosticSummary($data));
        }
        $status = ($channelStatus === 'S' || ($channelStatus === '' && $code === '00000000'))
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;

        return $this->refundResult($order, $data, $refundAmount, $status, $status === PaymentPluginStatusConstant::SUCCESS ? '退款成功' : '退款处理中');
    }

    /**
     * 解析并校验支付通知。
     *
     * 外层网关码成功后验证 resp_data 签名，并校验商户、金额、人民币币种及已知交易状态。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $outerCode = trim((string) ($request->post('resp_code') ?? ''));
        if ($outerCode !== '' && $outerCode !== '00000000') {
            throw new PaymentException('汇付回调网关状态失败', 40200, ['resp_code' => $outerCode]);
        }
        $respData = (string) ($request->post('resp_data') ?? '');
        $sign = (string) ($request->post('sign') ?? '');
        try {
            $valid = $this->client()->verifyNotify($respData, $sign);
        } catch (HuifuSdkException $e) {
            throw new PaymentException('汇付回调验签失败：' . $e->getMessage(), 40200);
        }
        if (!$valid) {
            throw new PaymentException('汇付回调验签失败', 40200);
        }

        try {
            $data = json_decode($respData, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentException('汇付回调数据不是合法 JSON', 40200);
        }
        if (!is_array($data)) {
            throw new PaymentException('汇付回调业务数据不是对象', 40200);
        }

        $payNo = $this->requiredText($data['req_seq_id'] ?? '', '汇付回调缺少支付单号');
        $this->assertMerchant($data, '回调');
        $amount = $this->amountToCents($data['trans_amt'] ?? null, '回调交易金额');
        $currency = strtoupper($this->firstText($data['currency'] ?? '', 'CNY'));
        if ($currency !== 'CNY') {
            throw new PaymentException('汇付回调币种不是 CNY', 40200, ['currency' => $currency]);
        }
        $channelStatus = $this->requiredText($data['trans_stat'] ?? '', '汇付回调缺少交易状态');
        if (!in_array($channelStatus, ['S', 'F', 'P', 'I'], true)) {
            throw new PaymentException('汇付回调交易状态未知', 40200, ['trans_stat' => $channelStatus]);
        }

        $status = $this->tradeStatus($channelStatus);
        $this->notifyAckOrderNo = $payNo;

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $amount,
            'message' => $this->firstText($data['resp_desc'] ?? '', $channelStatus),
            'chan_order_no' => $payNo,
            'chan_trade_no' => $this->firstText($data['hf_seq_id'] ?? '', $data['out_trans_id'] ?? ''),
            'channel_status' => $channelStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->normalizeTime($data['end_time'] ?? '') : null,
        ];
    }

    /**
     * 返回渠道要求的支付通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function notifySuccess(): string|Response
    {
        return 'RECV_ORD_ID_' . $this->notifyAckOrderNo;
    }

    /**
     * 返回渠道要求的支付通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 按支付方式发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 平台支付方式编码
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->scanPay($order, self::PRODUCT_BANK_SCAN, 'U_NATIVE'),
            'ecny' => $this->scanPay($order, self::PRODUCT_ECNY_SCAN, 'D_NATIVE'),
            'wxpay' => $this->scanPay($order, self::PRODUCT_WXPAY_SCAN, 'T_NATIVE'),
            'alipay' => $this->scanPay($order, self::PRODUCT_ALIPAY_SCAN, 'A_NATIVE'),
            default => throw new PaymentException('汇付不支持当前扫码支付方式', 40200),
        };
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 汇付产品编码
     * @param string $tradeType 汇付交易类型
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPay(array $order, string $product, string $tradeType): array
    {
        $this->ensureProduct($product);
        $data = $this->requestJspay($order, $tradeType);
        $qrcode = $this->requiredText($data['qr_code'] ?? '', '汇付扫码下单未返回二维码内容');

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'jspay',
            'pay_params' => ['qrcode' => $qrcode, 'raw' => $this->diagnosticSummary($data)],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['hf_seq_id'] ?? ''),
        ]);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 汇付产品编码
     * @param string $tradeType 汇付交易类型
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order, string $product, string $tradeType): array
    {
        $this->ensureProduct($product);
        $payment = $this->paymentPayload($order);
        if ($tradeType === 'A_JSAPI') {
            $userId = $this->requiredText($payment['buyer_id'] ?? '', '汇付支付宝 JSAPI 缺少 buyer_id');
            $this->assertScopedAppId($payment, 'alipay_app_id', $this->configText('alipay_app_id'), '支付宝');
        } elseif ($tradeType === 'T_MINIAPP') {
            $userId = $this->requiredText($payment['mini_openid'] ?? '', '汇付微信小程序支付缺少 mini_openid');
            $this->assertScopedAppId($payment, 'mini_app_id', $this->configText('wx_mini_app_id'), '微信小程序');
        } else {
            $userId = $this->requiredText($this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? ''), '汇付微信公众号支付缺少 sub_openid');
            $this->assertScopedAppId($payment, 'sub_appid', $this->configText('wx_mp_app_id'), '微信公众号');
        }

        $data = $this->requestJspay($order, $tradeType, $userId);
        try {
            $params = json_decode((string) ($data['pay_info'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentException('汇付 JSAPI 下单未返回合法支付参数', 40200, $this->diagnosticSummary($data));
        }
        if (!is_array($params)) {
            throw new PaymentException('汇付 JSAPI 下单未返回支付参数对象', 40200, $this->diagnosticSummary($data));
        }
        $params['raw'] = $this->diagnosticSummary($data);

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'jspay',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['hf_seq_id'] ?? ''),
        ]);
    }

    /**
     * 发起托管收银台支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 平台支付方式编码
     * @return array<string, mixed> 标准支付结果
     */
    private function hostedPay(array $order, string $payType): array
    {
        $product = $payType === 'wxpay' ? self::PRODUCT_WXPAY_HOSTED : self::PRODUCT_ALIPAY_HOSTED;
        $this->ensureProduct($product);
        $requestType = $this->pageRequestType($order);
        $hostingData = [
            'project_id' => $this->configText('hosted_project_id'),
            'project_title' => $this->firstText($this->configText('hosted_project_title'), 'MPAY 收银台'),
            'private_info' => (string) $order['pay_no'],
            'request_type' => $requestType,
        ];
        if ($this->firstText($order['return_url'] ?? '') !== '') {
            $hostingData['callback_url'] = (string) $order['return_url'];
        }

        $payload = $this->baseOrder($order, 40) + [
            'pre_order_type' => '1',
            'trans_type' => $payType === 'wxpay' ? 'T_JSAPI' : 'A_JSAPI',
            'hosting_data' => $this->encodeJson($hostingData, '托管扩展参数'),
        ];
        $data = $this->upstreamRequest('/v2/trade/hosting/payment/preorder', $payload, '托管预下单', true);
        $this->assertPayResponse($data, $order, '托管预下单', ['00000000']);
        $url = $this->requiredText($data['jump_url'] ?? '', '汇付托管预下单未返回跳转地址');

        return $this->jumpResult($order, $product, 'hosting/payment/preorder', $url, $data);
    }

    /**
     * 发起线上页面支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 汇付页面产品编码
     * @return array<string, mixed> 标准支付结果
     */
    private function onlinePagePay(array $order, string $product): array
    {
        $this->ensureProduct($product);
        $quick = $product === self::PRODUCT_QUICKPAY_PAGE;
        $path = $quick
            ? '/v2/trade/onlinepayment/quickpay/frontpay'
            : '/v2/trade/onlinepayment/banking/frontpay';
        $payload = $this->baseOrder($order, 64) + [
            'order_type' => 'P',
            'front_url' => (string) ($order['return_url'] ?? ''),
            'extend_pay_data' => $this->encodeJson([
                'goods_short_name' => mb_strcut((string) $order['subject'], 0, 39, 'UTF-8'),
                'biz_tp' => $this->configText($quick ? 'quickpay_biz_type' : 'bank_biz_type', '100099'),
                'gw_chnnl_tp' => $this->pageRequestType($order) === 'P' ? '01' : '02',
            ], '线上支付扩展参数'),
            'terminal_device_data' => $this->encodeJson([
                'device_type' => $this->pageRequestType($order) === 'P' ? '4' : '1',
                'device_ip' => (string) $order['client_ip'],
            ], '线上支付设备参数'),
        ];
        if ($quick) {
            $payload['request_type'] = $this->pageRequestType($order);
        } else {
            $payload['gate_type'] = $this->configText('bank_gate_type', '01');
            $payload['card_type'] = $this->configText('bank_card_type', 'D');
            $payload['bank_id'] = $this->configText('bank_id');
            $payload['delay_acct_flag'] = 'N';
        }

        $data = $this->upstreamRequest($path, $payload, $quick ? '快捷页面下单' : '网银页面下单', true);
        $operation = $quick ? '快捷页面下单' : '网银页面下单';
        $this->assertBusinessCode($data, $operation, ['00000000', '00000100']);
        $this->assertMerchantIfPresent($data, $operation);
        $this->assertResponseSequence($data, (string) $order['pay_no'], $operation);
        $this->assertOrderAmount($data, (int) $order['amount'], $operation);
        $url = $this->requiredText($data['form_url'] ?? '', '汇付线上支付未返回表单跳转地址');

        return $this->jumpResult($order, $product, ltrim($path, '/'), $url, $data);
    }

    /**
     * 发起付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $authCode 付款码
     * @return array<string, mixed> 标准支付结果
     */
    private function barcodePay(array $order, string $authCode): array
    {
        $this->ensureProduct(self::PRODUCT_BARCODE);
        $authCode = $this->requiredText($authCode, '汇付付款码支付缺少 auth_code');
        $data = $this->upstreamRequest('/v3/trade/payment/micropay', $this->baseOrder($order) + [
            'auth_code' => $authCode,
        ], '付款码下单', true);
        $this->assertPayResponse($data, $order, '付款码下单', ['00000000', '00000100']);
        $channelStatus = $this->requiredText($data['trans_stat'] ?? '', '汇付付款码下单未返回交易状态');
        if ($channelStatus === 'F') {
            throw new PaymentException('汇付付款码支付失败：' . $this->firstText($data['resp_desc'] ?? '', '渠道返回失败'), 40200, $this->diagnosticSummary($data));
        }
        if (!in_array($channelStatus, ['S', 'P', 'I'], true)) {
            throw new PaymentException('汇付付款码返回未知交易状态', 40200, $this->diagnosticSummary($data));
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'page',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => self::PRODUCT_BARCODE,
            'pay_action' => 'micropay',
            'pay_params' => [
                '_page' => 'paymentPending',
                'description' => $channelStatus === 'S'
                    ? '渠道已确认扣款，正在等待 MPAY 回调或主动查单确认订单。'
                    : '付款请求处理中，请勿重复扫码或改用其他支付产品。',
                'channel_status' => $channelStatus,
                'raw' => $this->diagnosticSummary($data),
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['hf_seq_id'] ?? ''),
        ]);
    }

    /**
     * 请求渠道 JS 支付接口。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $tradeType 汇付交易类型
     * @param string $userId 渠道用户标识
     * @return array<string, mixed> 汇付 JS 支付响应
     */
    private function requestJspay(array $order, string $tradeType, string $userId = ''): array
    {
        $payload = $this->baseOrder($order) + ['trade_type' => $tradeType];
        if ($tradeType === 'A_JSAPI') {
            $payload['alipay_data'] = $this->encodeJson([
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'buyer_id' => $userId,
            ], '支付宝参数');
        } elseif ($tradeType === 'A_NATIVE') {
            $payload['alipay_data'] = $this->encodeJson([
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            ], '支付宝参数');
        } elseif (in_array($tradeType, ['T_JSAPI', 'T_MINIAPP'], true)) {
            $appId = $tradeType === 'T_MINIAPP' ? $this->configText('wx_mini_app_id') : $this->configText('wx_mp_app_id');
            $wxData = [
                'sub_appid' => $appId,
                'sub_openid' => $userId,
                'device_info' => '4',
                'spbill_create_ip' => (string) $order['client_ip'],
            ];
            if ($tradeType === 'T_JSAPI') {
                $wxData['product_id'] = $this->configText('wx_goods_product_id', '01001');
            }
            $payload['wx_data'] = $this->encodeJson($wxData, '微信参数');
        } elseif ($tradeType === 'T_NATIVE') {
            $payload['wx_data'] = $this->encodeJson([
                'product_id' => $this->configText('wx_goods_product_id', '01001'),
                'spbill_create_ip' => (string) $order['client_ip'],
            ], '微信参数');
        }

        $data = $this->upstreamRequest('/v3/trade/payment/jspay', $payload, '聚合下单', true);
        $this->assertPayResponse($data, $order, '聚合下单', ['00000000', '00000100']);
        if ((string) ($data['trans_stat'] ?? '') === 'F') {
            throw new PaymentException('汇付下单失败：' . $this->firstText($data['resp_desc'] ?? '', '渠道返回失败'), 40200, $this->diagnosticSummary($data));
        }

        return $data;
    }

    /**
     * 构建渠道基础订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param int $subjectLength 商品描述最大长度
     * @return array<string, mixed> 渠道基础订单参数
     */
    private function baseOrder(array $order, int $subjectLength = 127): array
    {
        return [
            'req_date' => $this->originalRequestDate($order),
            'req_seq_id' => (string) $order['pay_no'],
            'huifu_id' => $this->huifuId(),
            'trans_amt' => FormatHelper::amount((int) $order['amount']),
            'goods_desc' => mb_strcut((string) $order['subject'], 0, $subjectLength, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
            'risk_check_data' => $this->encodeJson(['ip_addr' => (string) $order['client_ip']], '风控参数'),
        ];
    }

    /**
     * 构建跳转支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 汇付产品编码
     * @param string $action 渠道接口动作
     * @param string $url 渠道跳转地址
     * @param array<string, mixed> $data 汇付响应
     * @return array<string, mixed> 标准支付结果
     */
    private function jumpResult(array $order, string $product, string $action, string $url, array $data): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jump',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => ['url' => $url, 'raw' => $this->diagnosticSummary($data)],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['hf_seq_id'] ?? '', $data['pre_order_id'] ?? ''),
        ]);
    }

    /**
     * 构建标准退款结果。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @param array<string, mixed> $data 汇付响应
     * @param int $amount 退款金额，单位分
     * @param string $status 平台退款状态
     * @param string $message 退款状态说明
     * @return array<string, mixed> 标准退款结果
     */
    private function refundResult(array $order, array $data, int $amount, string $status, string $message): array
    {
        return [
            'status' => $status,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'chan_refund_no' => $this->firstText($data['hf_seq_id'] ?? '', $data['req_seq_id'] ?? '', $order['refund_no'] ?? ''),
            'refund_amount' => $amount,
            'channel_status' => (string) ($data['trans_stat'] ?? ''),
            'message' => $message,
        ];
    }

    /**
     * 构造标准关单结果。
     *
     * @param array<string, mixed> $order 标准关单参数
     * @param string $status 平台关单状态
     * @param string $message 关单状态说明
     * @return array<string, mixed> 标准关单结果
     */
    private function closeResult(array $order, string $status, string $message): array
    {
        return [
            'status' => $status,
            'pay_no' => (string) $order['pay_no'],
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => $message,
        ];
    }

    /**
     * 校验支付响应。
     *
     * @param array<string, mixed> $data 汇付响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $operation 业务动作说明
     * @param array<int, string> $accepted 可接受的响应码
     * @return void
     */
    private function assertPayResponse(array $data, array $order, string $operation, array $accepted): void
    {
        $this->assertBusinessCode($data, $operation, $accepted);
        $this->assertMerchant($data, $operation);
        $this->assertResponseSequence($data, (string) $order['pay_no'], $operation);
        $this->assertOrderAmount($data, (int) $order['amount'], $operation);
    }

    /**
     * 校验渠道业务响应码。
     *
     * @param array<string, mixed> $data 汇付响应
     * @param string $operation 业务动作说明
     * @param array<int, string> $accepted 可接受的响应码
     * @return void
     */
    private function assertBusinessCode(array $data, string $operation, array $accepted): void
    {
        $code = trim((string) ($data['resp_code'] ?? ''));
        if ($code === '20000000') {
            throw new PaymentException('汇付' . $operation . '返回重复交易，必须按原流水查单处理', 40200, $this->diagnosticSummary($data));
        }
        if ($code === '' || !in_array($code, $accepted, true)) {
            throw new PaymentException('汇付' . $operation . '失败：' . $this->firstText($data['resp_desc'] ?? '', $code, '渠道返回失败'), 40200, $this->diagnosticSummary($data));
        }
    }

    /**
     * 校验渠道商户号。
     *
     * @param array<string, mixed> $data 汇付响应或通知数据
     * @param string $operation 业务动作说明
     * @return void
     */
    private function assertMerchant(array $data, string $operation): void
    {
        $merchant = trim((string) ($data['huifu_id'] ?? ''));
        if ($merchant === '' || !hash_equals($this->huifuId(), $merchant)) {
            throw new PaymentException('汇付' . $operation . '商户号与当前通道不一致', 40200, [
                'expected_merchant_no' => $this->mask($this->huifuId()),
                'actual_merchant_no' => $this->mask($merchant),
            ]);
        }
    }

    /**
     * 校验响应中的可选商户号。
     *
     * @param array<string, mixed> $data 汇付响应
     * @param string $operation 业务动作说明
     * @return void
     */
    private function assertMerchantIfPresent(array $data, string $operation): void
    {
        if (trim((string) ($data['huifu_id'] ?? '')) !== '') {
            $this->assertMerchant($data, $operation);
        }
    }

    /**
     * 校验渠道请求序列号。
     *
     * @param array<string, mixed> $data 汇付响应
     * @param string $expected 预期请求序列号
     * @param string $operation 业务动作说明
     * @return void
     */
    private function assertResponseSequence(array $data, string $expected, string $operation): void
    {
        $actual = trim((string) ($data['req_seq_id'] ?? ''));
        if ($actual === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException('汇付' . $operation . '响应流水号与请求不一致', 40200, [
                'expected_req_seq_id' => $expected,
                'actual_req_seq_id' => $actual,
            ]);
        }
    }

    /**
     * 校验原交易标识。
     *
     * @param array<string, mixed> $data 汇付响应
     * @param string $expected 预期原交易请求序列号
     * @param string $operation 业务动作说明
     * @return void
     */
    private function assertOriginalOrder(array $data, string $expected, string $operation): void
    {
        $actual = trim((string) ($data['org_req_seq_id'] ?? ''));
        if ($actual !== '' && !hash_equals($expected, $actual)) {
            throw new PaymentException('汇付' . $operation . '原支付流水号不一致', 40200, [
                'expected_org_req_seq_id' => $expected,
                'actual_org_req_seq_id' => $actual,
            ]);
        }
    }

    /**
     * 校验渠道订单金额。
     *
     * @param array<string, mixed> $data 汇付响应
     * @param int $expectedCents 预期金额，单位分
     * @param string $operation 业务动作说明
     * @return void
     */
    private function assertOrderAmount(array $data, int $expectedCents, string $operation): void
    {
        if ($expectedCents <= 0 || !array_key_exists('trans_amt', $data) || trim((string) $data['trans_amt']) === '') {
            return;
        }
        $actual = $this->amountToCents($data['trans_amt'], $operation . '交易金额');
        if ($actual !== $expectedCents) {
            throw new PaymentException('汇付' . $operation . '金额与当前支付单不一致', 40200, [
                'expected_amount' => $expectedCents,
                'actual_amount' => $actual,
            ]);
        }
    }

    /**
     * 校验支付身份所属应用。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $field 应用 ID 字段名
     * @param string $configured 当前产品配置的应用 ID
     * @param string $label 应用类型说明
     * @return void
     */
    private function assertScopedAppId(array $payment, string $field, string $configured, string $label): void
    {
        $actual = trim((string) ($payment[$field] ?? ''));
        if ($actual !== '' && ($configured === '' || !hash_equals($configured, $actual))) {
            throw new PaymentException('汇付' . $label . '用户标识不属于当前配置 AppID 作用域', 40200, [
                'identity_app_id' => $actual,
                'configured_app_id' => $configured,
            ]);
        }
    }

    /**
     * 获取订单支付产品。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 下单产品快照
     */
    private function orderProduct(array $order): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if ($product === '') {
            throw new PaymentException('汇付后续操作缺少原支付产品', 40200);
        }
        if (!array_key_exists($product, self::PRODUCT_OPTIONS)) {
            throw new PaymentException('汇付订单记录了未知支付产品', 40200, ['pay_product' => $product]);
        }

        return $product;
    }

    /**
     * 获取原交易请求日期。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 原交易请求日期，格式 Ymd
     */
    private function originalRequestDate(array $order): string
    {
        $createdAt = trim((string) ($order['pay_created_at'] ?? ''));
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $createdAt, $matches) === 1) {
            return $matches[1] . $matches[2] . $matches[3];
        }

        throw new PaymentException('汇付后续操作缺少原支付请求日期', 40200);
    }

    /**
     * 解析页面支付请求类型。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string P 表示 PC，M 表示移动端
     */
    private function pageRequestType(array $order): string
    {
        return strtolower(trim((string) ($order['_env'] ?? 'pc'))) === 'pc' ? 'P' : 'M';
    }

    /**
     * 判断是否请求小程序支付。
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

        if ($this->firstText(
            $payment['mini_openid'] ?? '',
            $payment['mini_code'] ?? '',
            $payment['mini_app_id'] ?? ''
        ) !== '') {
            return true;
        }

        return in_array(strtolower(trim((string) ($payment['is_mini'] ?? ''))), ['1', 'true', 'yes', 'mini'], true);
    }

    /**
     * 获取支付扩展参数。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return array<string, mixed> 支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 构建渠道响应摘要。
     *
     * @param array<string, mixed> $data 汇付响应
     * @return array<string, mixed> 白名单化且已脱敏的响应摘要
     */
    private function diagnosticSummary(array $data): array
    {
        $allowed = [
            'resp_code', 'resp_desc', 'req_date', 'req_seq_id', 'org_req_date', 'org_req_seq_id',
            'hf_seq_id', 'org_hf_seq_id', 'pre_order_id', 'trade_type', 'pay_type', 'trans_type',
            'trans_stat', 'org_trans_stat', 'trans_amt', 'huifu_id', 'end_time', 'current_time', 'time_expire',
        ];
        $summary = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data) && !is_array($data[$field]) && !is_object($data[$field])) {
                $summary[$field] = $field === 'huifu_id'
                    ? $this->mask(trim((string) $data[$field]))
                    : $data[$field];
            }
        }

        return $summary;
    }

    /**
     * 将汇付元金额转换为整数分。
     *
     * @param mixed $amount 渠道金额
     * @param string $label 金额字段说明
     * @return int 金额，单位分
     */
    private function amountToCents(mixed $amount, string $label): int
    {
        $text = trim((string) $amount);
        if (preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $text) !== 1) {
            throw new PaymentException('汇付' . $label . '格式无效', 40200, ['amount' => $text]);
        }
        [$yuan, $decimal] = array_pad(explode('.', $text, 2), 2, '');

        return ((int) $yuan * 100) + (int) str_pad($decimal, 2, '0');
    }

    /**
     * 编码 JSON 数据。
     *
     * @param array<string, mixed> $value 待编码数据
     * @param string $label 数据说明
     * @return string JSON 文本
     */
    private function encodeJson(array $value, string $label): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentException('汇付' . $label . '编码失败', 40200);
        }
    }

    /**
     * 请求渠道接口。
     *
     * 只读请求使用普通支付异常；变更请求的网络、限流、服务端或响应解析异常保留为
     * 结果不确定，其余渠道明确拒绝映射为确定失败。
     *
     * @param string $path 渠道接口路径
     * @param array<string, mixed> $payload 请求参数
     * @param string $operation 业务动作说明
     * @param bool $mutation 是否为产生渠道状态变更的请求
     * @return array<string, mixed> 渠道响应
     */
    private function upstreamRequest(string $path, array $payload, string $operation, bool $mutation = false): array
    {
        try {
            return $this->client()->request($path, $payload);
        } catch (HuifuSdkException $e) {
            $uncertain = $e->getPrevious() instanceof \GuzzleHttp\Exception\GuzzleException
                || $e->getCode() >= 500
                || in_array($e->getCode(), [408, 429], true)
                || str_contains($e->getMessage(), '响应')
                || str_contains($e->getMessage(), '网关');
            $exception = !$mutation
                ? PaymentException::class
                : ($uncertain ? PaymentUncertainException::class : PaymentDefinitiveException::class);
            throw new $exception('汇付' . $operation . '失败：' . $e->getMessage(), 40200);
        }
    }

    /**
     * 获取当前通道的汇付客户端。
     *
     * @return HuifuClient
     */
    private function client(): HuifuClient
    {
        if ($this->client === null) {
            $this->client = new HuifuClient([
                'sys_id' => $this->configText('sys_id'),
                'product_id' => $this->configText('product_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'huifu_public_key' => $this->configText('huifu_public_key'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    private function huifuId(): string
    {
        return $this->firstText($this->configText('sub_merchant_no'), $this->configText('sys_id'));
    }

    /**
     * 将汇付交易状态映射为平台支付状态。
     *
     * @param string $status 汇付交易状态
     * @return string 平台支付状态
     */
    private function tradeStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'S' => PaymentPluginStatusConstant::SUCCESS,
            'F' => PaymentPluginStatusConstant::FAILED,
            'C' => PaymentPluginStatusConstant::CLOSED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前汇付通道未开启该支付产品', 40200, ['product' => $product]);
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

    private function configText(string $key, string $default = ''): string
    {
        return trim((string) $this->getConfig($key, $default));
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

    private function requiredText(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentException($message, 40200);
        }

        return $text;
    }

    private function actionSequence(string $prefix): string
    {
        return date('YmdHis') . $prefix . random_int(100000, 999999);
    }

    private function normalizeTime(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{14}$/', $text) === 1) {
            return substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2)
                . ' ' . substr($text, 8, 2) . ':' . substr($text, 10, 2) . ':' . substr($text, 12, 2);
        }

        return $text !== '' ? $text : null;
    }

    private function mask(string $value): string
    {
        $length = strlen($value);
        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 3) . str_repeat('*', $length - 6) . substr($value, -3);
    }

    /**
     * 构建文本输入配置字段。
     *
     * @param string $field 配置字段名
     * @param string $title 配置项标题
     * @param bool $required 是否必填
     * @param string $value 默认值
     * @return array<string, mixed> 文本输入配置
     */
    private function inputField(string $field, string $title, bool $required = false, string $value = ''): array
    {
        $schema = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => $value];
        if ($required) {
            $schema['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $schema;
    }
}
