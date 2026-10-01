<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\alipay\AlipayConfig;
use app\common\sdk\alipay\AlipaySdkException;
use app\common\sdk\fubei\FubeiClient;
use app\common\sdk\fubei\FubeiSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use GuzzleHttp\Exception\GuzzleException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use JsonException;
use support\Request;
use support\Response;

/**
 * 付呗开放接口支付插件。
 *
 * 提供支付宝 H5、支付宝生活号、微信公众号、查单、关单、退款和支付通知能力。
 * 插件负责按下单产品选择身份作用域，并在通知中校验通道、商户、门店、产品、金额及
 * 已保存的渠道流水；当前公开协议未确认云闪付产品，因此不声明 bank 支付方式。
 */
class FubeiApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';

    private const METHOD_ORDER_CREATE = 'fbpay.order.create';
    private const METHOD_ORDER_WAP_CREATE = 'fbpay.order.wap.create';
    private const METHOD_ORDER_QUERY = 'fbpay.order.query';
    private const METHOD_ORDER_CLOSE = 'fbpay.order.close';
    private const METHOD_ORDER_REFUND = 'fbpay.order.refund';

    private ?FubeiClient $client = null;

    /**
     * 插件元信息。
     *
     * 当前公开协议不支持云闪付 bank 产品，因此插件不声明 bank 支付方式，也不会请求上游。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'fubei_api',
        'name' => '付呗开放接口支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造付呗支付插件。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     */
    public function __construct(
        private readonly PayOrderRepository $payOrderRepository
    ) {
    }

    /**
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'vendor_sn',
                'title' => '服务商 Vendor SN',
                'value' => '',
                'props' => ['placeholder' => '服务商接入填写；与商户 App ID 二选一'],
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '商户 App ID',
                'value' => '',
                'props' => ['placeholder' => '商户直连接入填写；与 Vendor SN 二选一'],
            ],
            [
                'type' => 'password',
                'field' => 'app_secret',
                'title' => '接口密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '接口密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'fubei_merchant_id',
                'title' => '付呗商户 ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '付呗商户 ID 不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'store_id',
                'title' => '付呗门店 ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '付呗门店 ID 不能为空'],
                ],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通并完成实测的产品',
                'value' => [],
                'options' => [
                    ['label' => '支付宝 H5（待私有文档核对）', 'value' => self::PRODUCT_ALIPAY_H5],
                    ['label' => '支付宝生活号/JSAPI', 'value' => self::PRODUCT_ALIPAY_JSAPI],
                    ['label' => '微信公众号 JSAPI', 'value' => self::PRODUCT_WXPAY_JSAPI],
                ],
                'validate' => [
                    ['required' => true, 'message' => '至少选择一个已开通且完成实测的产品'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'wechat_app_id',
                'title' => '微信公众号 AppID',
                'value' => '',
                'props' => ['placeholder' => '微信公众号 JSAPI 必填，必须与 openid 作用域一致'],
            ],
            [
                'type' => 'password',
                'field' => 'wechat_app_secret',
                'title' => '微信公众号 AppSecret',
                'value' => '',
                'props' => ['placeholder' => '由 MPAY 身份服务完成网页授权'],
            ],
            [
                'type' => 'input',
                'field' => 'alipay_oauth_app_id',
                'title' => '支付宝生活号应用 AppID',
                'value' => '',
                'props' => ['placeholder' => '支付宝生活号/JSAPI 身份授权必填'],
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_oauth_private_key',
                'title' => '支付宝生活号应用私钥',
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
                'field' => 'alipay_oauth_sandbox',
                'title' => '支付宝 OAuth 沙箱',
                'value' => false,
            ],
            [
                'type' => 'input',
                'field' => 'api_gateway',
                'title' => '付呗 HTTPS 网关',
                'value' => '',
                'props' => [
                    'placeholder' => '必须使用付呗当前分配并加入白名单的网关',
                ],
                'validate' => [
                    ['required' => true, 'message' => '付呗网关地址不能为空'],
                    ['url' => true, 'message' => '付呗网关地址格式不正确'],
                ],
            ],
        ];
    }

    /**
     * 初始化并验证通道配置。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;

        if ($this->configText('vendor_sn') === '' && $this->configText('app_id') === '') {
            throw new PaymentException('付呗 vendor_sn 与 app_id 至少配置一项', 40200);
        }
        if ($this->configText('app_secret') === '') {
            throw new PaymentException('付呗接口密钥不能为空', 40200);
        }
        if ($this->fubeiMerchantId() === '' || $this->configText('store_id') === '') {
            throw new PaymentException('付呗商户 ID 与门店 ID 不能为空', 40200);
        }
        $gateway = strtolower($this->configText('api_gateway'));
        if (!str_starts_with($gateway, 'https://')) {
            throw new PaymentException('付呗网关地址必须使用 HTTPS', 40200);
        }

        $enabled = $this->enabledProducts();
        $supported = [
            self::PRODUCT_ALIPAY_H5,
            self::PRODUCT_ALIPAY_JSAPI,
            self::PRODUCT_WXPAY_JSAPI,
        ];
        if ($enabled === [] || array_diff($enabled, $supported) !== []) {
            throw new PaymentException('付呗已开通产品配置为空或包含未验证产品', 40200, [
                'enabled_products' => $enabled,
                'supported_products' => $supported,
            ]);
        }

        if (in_array(self::PRODUCT_WXPAY_JSAPI, $enabled, true)
            && ($this->configText('wechat_app_id') === '' || $this->configText('wechat_app_secret') === '')) {
            throw new PaymentException('微信公众号 JSAPI 必须配置公众号 AppID 与 AppSecret', 40200);
        }

        if (in_array(self::PRODUCT_ALIPAY_JSAPI, $enabled, true)) {
            try {
                new AlipayConfig($this->alipayOAuthConfig());
            } catch (AlipaySdkException $e) {
                throw new PaymentException('支付宝生活号 OAuth 配置无效：' . $e->getMessage(), 40200);
            }
        }
    }

    /**
     * 声明 JSAPI 用户身份需求。
     *
     * 仅在微信或支付宝内且相应 JSAPI 产品已启用时声明身份要求；已有当前应用作用域
     * 的 openid 或 buyer_id 时不重复授权。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；无需补充身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));
        $payment = $this->paymentPayload($order);

        if ($payType === 'wxpay'
            && $env === 'wechat'
            && in_array(self::PRODUCT_WXPAY_JSAPI, $this->enabledProducts(), true)) {
            if ($this->firstText(
                $payment['sub_openid'] ?? '',
                $payment['openid'] ?? '',
                $payment['wx_openid'] ?? ''
            ) !== '') {
                return null;
            }

            return [
                'provider' => 'wxpay',
                'product' => self::PRODUCT_WXPAY_JSAPI,
                'channel_product' => self::PRODUCT_WXPAY_JSAPI,
                'auth_type' => 'wechat_oauth',
                'identity_field' => 'openid',
                'app_id' => $this->configText('wechat_app_id'),
                '_app_secret' => $this->configText('wechat_app_secret'),
                'scope' => 'snsapi_base',
                'message' => '微信公众号 JSAPI 支付需要先获取当前公众号作用域的 openid',
            ];
        }

        if ($payType === 'alipay'
            && $env === 'alipay'
            && in_array(self::PRODUCT_ALIPAY_JSAPI, $this->enabledProducts(), true)) {
            if ($this->firstText($payment['buyer_id'] ?? '', $payment['buyer_open_id'] ?? '') !== '') {
                return null;
            }

            return [
                'provider' => 'alipay',
                'product' => self::PRODUCT_ALIPAY_JSAPI,
                'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
                'auth_type' => 'alipay_oauth',
                'identity_field' => 'buyer_id',
                'identity_aliases' => ['buyer_open_id'],
                'app_id' => $this->configText('alipay_oauth_app_id'),
                'scope' => 'auth_base',
                '_alipay_config' => $this->alipayOAuthConfig(),
                'message' => '支付宝生活号/JSAPI 支付需要先获取 buyer_id 或 buyer_open_id',
            ];
        }

        return null;
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

        return $this->executeDirectPaymentProduct($order, [
            'jsapi' => [
                'products' => [
                    'wxpay' => self::PRODUCT_WXPAY_JSAPI,
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                ],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, 'wxpay'),
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'alipay'),
                        default => throw new PaymentException('付呗不支持当前 JSAPI 支付方式', 40200),
                    };
                },
            ],
            'h5' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5($order),
            ],
            'jump' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5($order),
            ],
            'web' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5($order),
            ],
        ], '付呗');
    }

    /**
     * 主动查询订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $data = $this->execute(self::METHOD_ORDER_QUERY, [
            'merchant_id' => $this->fubeiMerchantId(),
            'merchant_order_sn' => $payNo,
        ], '查单');

        $this->assertResponseOrder($data, $order, '付呗查单');
        $channelStatus = strtoupper(trim((string) ($data['order_status'] ?? '')));
        $status = match ($channelStatus) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED' => PaymentPluginStatusConstant::CLOSED,
            'USERPAYING', 'UNUSED' => PaymentPluginStatusConstant::PENDING,
            default => throw new PaymentException('付呗查单返回未知订单状态', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $channelStatus,
            ]),
        };

        $channelOrderNo = trim((string) ($data['order_sn'] ?? ''));
        $channelTradeNo = trim((string) ($data['channel_order_sn'] ?? ''));
        if ($channelOrderNo === '') {
            throw new PaymentException('付呗查单响应缺少 order_sn', 40200, ['pay_no' => $payNo]);
        }
        if ($status === PaymentPluginStatusConstant::SUCCESS && $channelTradeNo === '') {
            throw new PaymentException('付呗成功查单响应缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->yuanToCents((string) $data['total_amount'], '付呗查单')
                : null,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $channelStatus,
            'message' => $channelStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->normalizeFubeiTime($data['finish_time'] ?? $data['pay_time'] ?? '')
                : null,
            'raw_data' => $this->responseSummary(self::METHOD_ORDER_QUERY, $data),
        ];
    }

    /**
     * 关闭未支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $data = $this->execute(self::METHOD_ORDER_CLOSE, [
            'merchant_id' => $this->fubeiMerchantId(),
            'merchant_order_sn' => $payNo,
        ], '关单', true);

        $responsePayNo = trim((string) ($data['merchant_order_sn'] ?? ''));
        if ($responsePayNo !== '' && !hash_equals($payNo, $responsePayNo)) {
            throw new PaymentException('付呗关单响应订单号不匹配', 40200, [
                'pay_no' => $payNo,
                'response_pay_no' => $responsePayNo,
            ]);
        }

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => $payNo,
            'chan_order_no' => trim((string) ($data['order_sn'] ?? $order['chan_order_no'] ?? '')),
            'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
            'message' => '付呗订单已关闭',
        ];
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $refundNo = trim((string) ($order['refund_no'] ?? ''));
        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        if ($refundNo === '' || $refundAmount <= 0) {
            throw new PaymentDefinitiveException('付呗退款单号与退款金额不能为空', 40200);
        }

        $data = $this->execute(self::METHOD_ORDER_REFUND, [
            'merchant_id' => $this->fubeiMerchantId(),
            'merchant_order_sn' => $payNo,
            'merchant_refund_sn' => $refundNo,
            'refund_amount' => FormatHelper::amount($refundAmount),
        ], '退款', true);

        $responseRefundNo = trim((string) ($data['merchant_refund_sn'] ?? ''));
        if ($responseRefundNo !== '' && !hash_equals($refundNo, $responseRefundNo)) {
            throw new PaymentException('付呗退款响应退款单号不匹配', 40200, [
                'refund_no' => $refundNo,
                'response_refund_no' => $responseRefundNo,
            ]);
        }
        if (isset($data['refund_amount'])) {
            $actualRefundAmount = $this->yuanToCents((string) $data['refund_amount'], '付呗退款响应');
            if ($actualRefundAmount !== $refundAmount) {
                throw new PaymentException('付呗退款响应金额不匹配', 40200, [
                    'refund_no' => $refundNo,
                    'refund_amount' => $refundAmount,
                    'response_refund_amount' => $actualRefundAmount,
                ]);
            }
        }

        $refundStatus = strtoupper(trim((string) ($data['refund_status'] ?? $data['status'] ?? '')));
        $channelRefundNo = $this->firstText($data['refund_sn'] ?? '', $responseRefundNo, $refundNo);
        return match ($refundStatus) {
            'REFUND_SUCCESS', 'SUCCESS' => [
                'status' => PaymentPluginStatusConstant::SUCCESS,
                'refund_no' => $refundNo,
                'pay_no' => $payNo,
                'refund_amount' => $refundAmount,
                'chan_refund_no' => $channelRefundNo,
                'channel_status' => $refundStatus,
                'message' => '付呗退款成功',
            ],
            'REFUND_PROCESSING', 'PROCESSING', '' => [
                'status' => PaymentPluginStatusConstant::PENDING,
                'refund_no' => $refundNo,
                'pay_no' => $payNo,
                'refund_amount' => $refundAmount,
                'chan_refund_no' => $channelRefundNo,
                'channel_status' => $refundStatus,
                'message' => $refundStatus === '' ? '付呗已受理退款，但未返回可确认的退款状态' : '付呗退款处理中',
            ],
            'REFUND_FAIL', 'FAIL', 'FAILED' => throw new PaymentDefinitiveException(
                trim((string) ($data['refund_message'] ?? $data['message'] ?? '')) ?: '付呗退款失败',
                40200,
                ['refund_no' => $refundNo, 'refund_status' => $refundStatus]
            ),
            default => throw new PaymentUncertainException('付呗退款返回未知状态', 40200, [
                'refund_no' => $refundNo,
                'refund_status' => $refundStatus,
            ]),
        };
    }

    /**
     * 解析并校验支付成功回调。
     *
     * POST 表单先验签并检查 result_code，再由 validatedNotifyData() 与本地支付单强关联。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        return $this->validatedNotifyPayload((array) $request->post());
    }

    /**
     * 对付呗 POST 表单执行签名和订单级业务校验。
     *
     * @param array<string, mixed> $payload 回调表单
     * @return array<string, mixed> 标准支付通知结果
     */
    private function validatedNotifyPayload(array $payload): array
    {
        if ($payload === [] || !$this->client()->verify($payload)) {
            throw new PaymentException('付呗回调验签失败', 40200);
        }
        if ((int) ($payload['result_code'] ?? 0) !== 200) {
            throw new PaymentException('付呗回调结果码不是成功', 40200, [
                'result_code' => (string) ($payload['result_code'] ?? ''),
            ]);
        }

        try {
            $data = json_decode((string) ($payload['data'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('付呗回调 data 不是合法 JSON', 40200);
        }
        if (!is_array($data)) {
            throw new PaymentException('付呗回调 data 不是对象', 40200);
        }

        return $this->validatedNotifyData($data);
    }

    /**
     * 校验验签后的回调数据与本地支付单强关联。
     *
     * @param array<string, mixed> $data 付呗回调 data
     * @return array<string, mixed> 标准支付通知结果
     */
    private function validatedNotifyData(array $data): array
    {
        $payNo = trim((string) ($data['merchant_order_sn'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('付呗回调缺少 merchant_order_sn', 40200);
        }

        $payOrder = $this->payOrderRepository->findByPayNo(
            $payNo,
            ['pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no', 'ext_json']
        );
        if (!$payOrder) {
            throw new PaymentException('付呗回调对应的支付单不存在', 40200, ['pay_no' => $payNo]);
        }

        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('付呗回调支付单不属于当前通道', 40200, [
                'pay_no' => $payNo,
                'channel_id' => $channelId,
                'order_channel_id' => (int) $payOrder->channel_id,
            ]);
        }

        $this->assertFieldEquals('uid', $data['uid'] ?? $data['merchant_id'] ?? '', $this->fubeiMerchantId(), $payNo);
        $this->assertFieldEquals('store_id', $data['store_id'] ?? '', $this->configText('store_id'), $payNo);

        $status = strtoupper(trim((string) ($data['order_status'] ?? '')));
        if ($status !== 'SUCCESS') {
            throw new PaymentException('付呗仅允许处理 SUCCESS 支付通知', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $status,
            ]);
        }

        $product = $this->payOrderProduct($payOrder);
        $expectedPayType = $this->payTypeForProduct($product);
        $actualPayType = strtolower(trim((string) ($data['pay_type'] ?? '')));
        if ($actualPayType === '' || !hash_equals($expectedPayType, $actualPayType)) {
            throw new PaymentException('付呗回调支付类型与下单产品不一致', 40200, [
                'pay_no' => $payNo,
                'pay_product' => $product,
                'pay_type' => $actualPayType,
            ]);
        }

        $amount = $this->yuanToCents((string) ($data['total_amount'] ?? ''), '付呗回调');
        if ($amount !== (int) $payOrder->pay_amount) {
            throw new PaymentException('付呗回调金额与支付单金额不一致', 40200, [
                'pay_no' => $payNo,
                'notify_amount' => $amount,
                'order_amount' => (int) $payOrder->pay_amount,
            ]);
        }
        $this->assertCnyIfPresent($data);

        $orderSn = trim((string) ($data['order_sn'] ?? ''));
        $channelOrderSn = trim((string) ($data['channel_order_sn'] ?? ''));
        if ($orderSn === '' || $channelOrderSn === '') {
            throw new PaymentException('付呗成功回调缺少 order_sn 或 channel_order_sn', 40200, [
                'pay_no' => $payNo,
            ]);
        }
        $storedOrderSn = trim((string) ($payOrder->channel_order_no ?? ''));
        // WAP 响应可能只返回 HTML/URL 而没有 order_sn；此时先使用 pay_no 关联订单，
        // 首次可信回调再写入真实付呗 order_sn。
        if ($storedOrderSn !== ''
            && !hash_equals($storedOrderSn, $payNo)
            && !hash_equals($storedOrderSn, $orderSn)) {
            throw new PaymentException('付呗回调 order_sn 与下单记录不一致', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $channelOrderSn)) {
            throw new PaymentException('付呗重复回调渠道交易号不一致', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $amount,
            'message' => $status,
            'chan_order_no' => $orderSn,
            'chan_trade_no' => $channelOrderSn,
            'channel_status' => $status,
            'paid_at' => $this->normalizeFubeiTime($data['finish_time'] ?? $data['pay_time'] ?? ''),
        ];
    }

    /**
     * 返回付呗成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回付呗失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 支付宝 H5 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function alipayH5(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_ALIPAY_H5);
        $data = $this->execute(self::METHOD_ORDER_WAP_CREATE, $this->baseOrder($order) + [
            'user_ip' => (string) ($order['client_ip'] ?? ''),
            'return_url' => (string) ($order['return_url'] ?? ''),
        ], '支付宝 H5 下单', true);

        $html = trim((string) ($data['html'] ?? $data['pay_url'] ?? ''));
        if ($html === '') {
            throw new PaymentException('付呗支付宝 H5 未返回支付内容', 40200, [
                'summary' => $this->responseSummary(self::METHOD_ORDER_WAP_CREATE, $data),
            ]);
        }

        $isUrl = str_starts_with(strtolower($html), 'https://');
        return $this->pendingPaymentResult($order, [
            'pay_page' => $isUrl ? 'jump' : 'html',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_ALIPAY_H5,
            'pay_action' => self::METHOD_ORDER_WAP_CREATE,
            'pay_params' => $isUrl
                ? ['url' => $html, 'raw' => $this->responseSummary(self::METHOD_ORDER_WAP_CREATE, $data)]
                : ['html' => $html, 'raw' => $this->responseSummary(self::METHOD_ORDER_WAP_CREATE, $data)],
            'chan_order_no' => $this->firstText($data['order_sn'] ?? '', $order['pay_no'] ?? ''),
            'chan_trade_no' => '',
        ]);
    }

    /**
     * JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 平台产品编码
     * @param string $payType 付呗 pay_type
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $this->ensureProduct($product);
        $payment = $this->paymentPayload($order);

        if ($product === self::PRODUCT_WXPAY_JSAPI) {
            $userId = $this->firstText(
                $payment['sub_openid'] ?? '',
                $payment['openid'] ?? '',
                $payment['wx_openid'] ?? ''
            );
            if ($userId === '') {
                throw new PaymentException('付呗微信公众号 JSAPI 缺少公众号 openid', 40200);
            }
            $configuredAppId = $this->configText('wechat_app_id');
            $inputAppId = trim((string) ($payment['sub_appid'] ?? ''));
            if ($inputAppId !== '' && !hash_equals($configuredAppId, $inputAppId)) {
                throw new PaymentException('付呗微信公众号 openid 的 AppID 作用域不匹配', 40200);
            }

            return $this->createJsapiOrder($order, $product, $payType, $userId, $configuredAppId);
        }

        $userId = $this->firstText($payment['buyer_id'] ?? '', $payment['buyer_open_id'] ?? '');
        if ($userId === '') {
            throw new PaymentException('付呗支付宝生活号/JSAPI 缺少 buyer_id 或 buyer_open_id', 40200);
        }

        return $this->createJsapiOrder($order, $product, $payType, $userId, '');
    }

    /**
     * 统一 JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 平台产品编码
     * @param string $payType 付呗 pay_type
     * @param string $userId 用户身份
     * @param string $subAppId 微信公众号 AppID
     * @return array<string, mixed> 标准支付结果
     */
    private function createJsapiOrder(
        array $order,
        string $product,
        string $payType,
        string $userId,
        string $subAppId
    ): array {
        $payload = $this->baseOrder($order) + [
            'pay_type' => $payType,
            'pay_way' => '02',
            'user_id' => $userId,
        ];
        if ($subAppId !== '') {
            $payload['sub_appid'] = $subAppId;
        }

        $data = $this->execute(self::METHOD_ORDER_CREATE, $payload, 'JSAPI 下单', true);
        $orderSn = trim((string) ($data['order_sn'] ?? ''));
        if ($orderSn === '') {
            throw new PaymentException('付呗 JSAPI 下单未返回 order_sn', 40200, [
                'summary' => $this->responseSummary(self::METHOD_ORDER_CREATE, $data),
            ]);
        }

        $params = $this->jsapiParams($product, $data);
        $params['raw'] = $this->responseSummary(self::METHOD_ORDER_CREATE, $data);

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jsapi',
            'pay_type' => (string) ($order['pay_type_code'] ?? ''),
            'pay_product' => $product,
            'pay_action' => self::METHOD_ORDER_CREATE,
            'pay_params' => $params,
            'chan_order_no' => $orderSn,
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 提取实际前端拉起所需参数。
     *
     * @param string $product 平台产品编码
     * @param array<string, mixed> $data 付呗响应
     * @return array<string, mixed> 前端调起参数
     */
    private function jsapiParams(string $product, array $data): array
    {
        $signPackage = $data['sign_package'] ?? [];
        if (is_string($signPackage) && trim($signPackage) !== '') {
            $decoded = json_decode($signPackage, true);
            $signPackage = is_array($decoded) ? $decoded : [];
        }

        if ($product === self::PRODUCT_WXPAY_JSAPI) {
            if (!is_array($signPackage) || $signPackage === []) {
                throw new PaymentException('付呗微信 JSAPI 下单未返回 sign_package', 40200);
            }

            return $signPackage;
        }

        $prepayId = trim((string) ($data['prepay_id'] ?? ''));
        if ($prepayId === '' && is_array($signPackage)) {
            $prepayId = $this->firstText(
                $signPackage['tradeNO'] ?? '',
                $signPackage['trade_no'] ?? '',
                $signPackage['prepay_id'] ?? ''
            );
        }
        if ($prepayId === '') {
            throw new PaymentException('付呗支付宝 JSAPI 下单未返回 prepay_id', 40200);
        }

        return ['tradeNO' => $prepayId];
    }

    /**
     * 构造基础订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 渠道基础订单参数
     */
    private function baseOrder(array $order): array
    {
        $payNo = $this->orderPayNo($order);
        $amount = (int) ($order['amount'] ?? 0);
        $callbackUrl = trim((string) ($order['callback_url'] ?? ''));
        if ($amount <= 0 || $callbackUrl === '') {
            throw new PaymentException('付呗下单金额与回调地址不能为空', 40200, ['pay_no' => $payNo]);
        }

        return [
            'merchant_id' => $this->fubeiMerchantId(),
            'merchant_order_sn' => $payNo,
            'total_amount' => FormatHelper::amount($amount),
            'store_id' => $this->configText('store_id'),
            'body' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
            'notify_url' => $callbackUrl,
        ];
    }

    /**
     * 校验查单响应与本地订单参数一致。
     *
     * @param array<string, mixed> $data 付呗查单响应
     * @param array<string, mixed> $order 标准查单参数
     * @param string $scene 业务场景说明
     * @return void
     */
    private function assertResponseOrder(array $data, array $order, string $scene): void
    {
        $payNo = $this->orderPayNo($order);
        $this->assertFieldEquals('merchant_order_sn', $data['merchant_order_sn'] ?? '', $payNo, $payNo);
        $this->assertFieldEquals('merchant_id', $data['merchant_id'] ?? '', $this->fubeiMerchantId(), $payNo);
        $this->assertFieldEquals('store_id', $data['store_id'] ?? '', $this->configText('store_id'), $payNo);

        $expectedAmount = (int) ($order['amount'] ?? -1);
        $actualAmount = $this->yuanToCents((string) ($data['total_amount'] ?? ''), $scene);
        if ($expectedAmount < 0 || $actualAmount !== $expectedAmount) {
            throw new PaymentException($scene . '金额与支付单不一致', 40200, [
                'pay_no' => $payNo,
                'response_amount' => $actualAmount,
                'order_amount' => $expectedAmount,
            ]);
        }

        $expectedProduct = $this->orderProduct($order);
        $expectedPayType = $this->payTypeForProduct($expectedProduct);
        $actualPayType = strtolower(trim((string) ($data['pay_type'] ?? '')));
        if ($actualPayType === '' || !hash_equals($expectedPayType, $actualPayType)) {
            throw new PaymentException($scene . '支付类型与下单产品不一致', 40200, [
                'pay_no' => $payNo,
                'pay_product' => $expectedProduct,
                'pay_type' => $actualPayType,
            ]);
        }
    }

    /**
     * 从订单数组读取下单产品快照。
     *
     * @param array<string, mixed> $order 标准订单参数
     * @return string 下单产品快照
     */
    private function orderProduct(array $order): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if ($product === '') {
            throw new PaymentException('付呗支付单缺少下单产品快照', 40200, [
                'pay_no' => $this->orderPayNo($order),
            ]);
        }

        return $product;
    }

    /**
     * 从模型读取下单产品快照。
     *
     * @param PayOrder $payOrder 支付单
     * @return string 下单产品快照
     */
    private function payOrderProduct(PayOrder $payOrder): string
    {
        $extra = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extra['payment_context'] ?? []);
        $product = trim((string) ($context['pay_product'] ?? ''));
        if ($product === '') {
            throw new PaymentException('付呗支付单缺少下单产品快照', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        return $product;
    }

    /**
     * 将平台产品映射为付呗 pay_type。
     *
     * @param string $product 平台产品编码
     * @return string 付呗支付类型
     */
    private function payTypeForProduct(string $product): string
    {
        return match ($product) {
            self::PRODUCT_ALIPAY_H5, self::PRODUCT_ALIPAY_JSAPI => 'alipay',
            self::PRODUCT_WXPAY_JSAPI => 'wxpay',
            default => throw new PaymentException('付呗支付单产品不受支持', 40200, [
                'pay_product' => $product,
            ]),
        };
    }

    /**
     * 校验回调商户或门店字段。
     *
     * @param string $field 字段名
     * @param mixed $actual 通知值
     * @param string $expected 当前通道配置值
     * @param string $payNo 平台支付单号
     * @return void
     */
    private function assertFieldEquals(string $field, mixed $actual, string $expected, string $payNo): void
    {
        $actual = trim((string) $actual);
        if ($actual === '' || $expected === '' || !hash_equals($expected, $actual)) {
            throw new PaymentException('付呗回调/响应 ' . $field . ' 不匹配', 40200, [
                'pay_no' => $payNo,
                'field' => $field,
            ]);
        }
    }

    /**
     * 官方成功回调未定义独立币种字段；若实际上游附带，则只接受人民币。
     *
     * @param array<string, mixed> $data 回调 data
     * @return void
     */
    private function assertCnyIfPresent(array $data): void
    {
        foreach (['currency', 'currency_code', 'fee_type'] as $field) {
            if (!array_key_exists($field, $data) || trim((string) $data[$field]) === '') {
                continue;
            }
            $currency = strtoupper(trim((string) $data[$field]));
            if (!in_array($currency, ['CNY', 'RMB', '156'], true)) {
                throw new PaymentException('付呗回调币种不是人民币', 40200, [
                    'field' => $field,
                    'currency' => $currency,
                ]);
            }
        }
    }

    /**
     * 将非负元金额精确转换为整数分。
     *
     * @param string $amount 渠道金额
     * @param string $scene 金额字段说明
     * @return int 金额，单位分
     */
    private function yuanToCents(string $amount, string $scene): int
    {
        $amount = trim($amount);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new PaymentException($scene . '金额格式无效', 40200);
        }

        $whole = $matches[1];
        if (strlen($whole) > strlen((string) intdiv(PHP_INT_MAX, 100))) {
            throw new PaymentException($scene . '金额超过系统整数范围', 40200);
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 2, '0');
        $wholeValue = (int) $whole;
        if ($wholeValue > intdiv(PHP_INT_MAX - (int) $fraction, 100)) {
            throw new PaymentException($scene . '金额超过系统整数范围', 40200);
        }

        return $wholeValue * 100 + (int) $fraction;
    }

    /**
     * 规范化付呗 yyyyMMddHHmmss 时间。
     *
     * @param mixed $value 渠道时间
     * @return string|null 平台时间；空值或格式不合法时返回 null
     */
    private function normalizeFubeiTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{14}$/', $value) === 1) {
            $time = DateTimeImmutable::createFromFormat('!YmdHis', $value);
            if ($time instanceof DateTimeImmutable) {
                return $time->format('Y-m-d H:i:s');
            }
        }

        return $value;
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return FubeiClient
     */
    private function client(): FubeiClient
    {
        if ($this->client === null) {
            $this->client = new FubeiClient([
                'vendor_sn' => $this->configText('vendor_sn'),
                'app_id' => $this->configText('app_id'),
                'app_secret' => $this->configText('app_secret'),
                'api_gateway' => $this->configText('api_gateway'),
            ]);
        }

        return $this->client;
    }

    /**
     * 调用 SDK 并按请求性质转换渠道异常。
     *
     * 只读请求使用普通支付异常；变更请求的明确业务拒绝属于确定失败，网络、超时、限流
     * 或服务端异常属于结果不确定，避免在渠道可能已受理时错误重试。
     *
     * @param string $method 付呗方法
     * @param array<string, mixed> $payload 业务参数
     * @param string $scene 场景名称
     * @param bool $mutation 是否为产生渠道状态变更的请求
     * @return array<string, mixed> 渠道业务响应
     */
    private function execute(string $method, array $payload, string $scene, bool $mutation = false): array
    {
        try {
            return $this->client()->execute($method, $payload);
        } catch (FubeiSdkException $e) {
            $data = $e->getData();
            $httpStatus = (int) ($data['http_status'] ?? 0);
            $isBusinessFailure = $httpStatus >= 200
                && $httpStatus < 300
                && (string) ($data['result_code'] ?? '') !== '';
            $isNetworkFailure = $e->getPrevious() instanceof GuzzleException
                || $httpStatus >= 500
                || in_array($httpStatus, [408, 429], true);
            $exception = !$mutation
                ? PaymentException::class
                : ($isBusinessFailure && !$isNetworkFailure
                    ? PaymentDefinitiveException::class
                    : ($isNetworkFailure ? PaymentUncertainException::class : PaymentDefinitiveException::class));

            throw new $exception('付呗' . $scene . '失败：' . $e->getMessage(), 40200, [
                'channel_error_code' => (string) ($e->getData()['sub_code'] ?? $e->getData()['result_code'] ?? 'FUBEI_API_ERROR'),
                ...$e->getData(),
            ]);
        }
    }

    /**
     * 仅保留可安全落库的响应摘要。
     *
     * @param string $method 付呗方法
     * @param array<string, mixed> $data 响应 data
     * @return array<string, mixed>
     */
    private function responseSummary(string $method, array $data): array
    {
        $fields = array_values(array_filter(array_map('strval', array_keys($data)), static function (string $field): bool {
            return !in_array($field, ['sign', 'sign_package', 'user_id', 'openid', 'buyer_id'], true);
        }));
        sort($fields);

        return array_filter([
            'method' => $method,
            'order_sn' => trim((string) ($data['order_sn'] ?? '')),
            'merchant_order_sn' => trim((string) ($data['merchant_order_sn'] ?? '')),
            'channel_order_sn' => trim((string) ($data['channel_order_sn'] ?? '')),
            'refund_sn' => trim((string) ($data['refund_sn'] ?? '')),
            'order_status' => trim((string) ($data['order_status'] ?? '')),
            'refund_status' => trim((string) ($data['refund_status'] ?? $data['status'] ?? '')),
            'response_fields' => $fields,
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);
    }

    /**
     * 校验产品开关。
     *
     * @param string $product 平台产品编码
     * @return void
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前付呗通道未开启该支付产品', 40200, ['product' => $product]);
        }
    }

    /**
     * 获取启用产品列表。
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
     * 获取付呗商户号。
     *
     * 优先读取专用配置键，并兼容已保存的 `merchant_id` 配置。
     *
     * @return string 付呗商户号
     */
    private function fubeiMerchantId(): string
    {
        return $this->firstText(
            $this->configText('fubei_merchant_id'),
            $this->configText('merchant_id')
        );
    }

    /**
     * 支付扩展参数。
     *
     * @param array<string, mixed> $order 标准订单参数
     * @return array<string, mixed>
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 获取标准支付单号。
     *
     * @param array<string, mixed> $order 标准订单参数
     * @return string 平台支付单号
     */
    private function orderPayNo(array $order): string
    {
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('付呗请求缺少支付单号', 40200);
        }

        return $payNo;
    }

    /**
     * 支付宝生活号 OAuth SDK 配置。
     *
     * @return array<string, mixed>
     */
    private function alipayOAuthConfig(): array
    {
        return [
            'mode' => AlipayConfig::MODE_KEY,
            'app_id' => $this->configText('alipay_oauth_app_id'),
            'private_key' => $this->configText('alipay_oauth_private_key'),
            'alipay_public_key' => $this->configText('alipay_oauth_public_key'),
            'sandbox' => $this->configBool('alipay_oauth_sandbox'),
        ];
    }

    /**
     * 获取字符串配置。
     *
     * @param string $key 配置键
     * @param string $default 默认值
     * @return string 配置值
     */
    private function configText(string $key, string $default = ''): string
    {
        return trim((string) $this->getConfig($key, $default));
    }

    /**
     * 获取布尔配置。
     *
     * @param string $key 配置键
     * @param bool $default 默认值
     * @return bool 配置值
     */
    private function configBool(string $key, bool $default = false): bool
    {
        $value = $this->getConfig($key, $default);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * 返回第一个非空字符串。
     *
     * @param mixed ...$values 候选值
     * @return string 首个非空文本
     */
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
