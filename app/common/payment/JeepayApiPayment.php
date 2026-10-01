<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\interface\RefundNotifyInterface;
use app\common\sdk\jeepay\JeepayClient;
use app\common\sdk\jeepay\JeepaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DOMDocument;
use DOMElement;
use JsonException;
use support\Request;
use support\Response;

/**
 * Jeepay V3.1.0 商户 API 1.0 支付插件。
 *
 * 提供配置型聚合支付、支付宝/微信直接 JSAPI、退款及独立支付/退款通知能力。
 * 产品一经选定只请求一个 wayCode，不跨产品重试；上游表单承接会重建为仅包含可信
 * HTTPS action 与 hidden 字段的最小页面，避免直接输出不受控 HTML。
 */
class JeepayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface,
    RefundNotifyInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_ALIPAY_CONFIGURED = 'alipay_configured';
    private const PRODUCT_WXPAY_CONFIGURED = 'wxpay_configured';
    private const PRODUCT_BANK_CONFIGURED = 'bank_configured';

    private const WAY_ALI_JSAPI = 'ALI_JSAPI';
    private const WAY_WX_JSAPI = 'WX_JSAPI';
    private const WAY_WX_LITE = 'WX_LITE';
    private const API_PROFILE = 'jeepay-v3.1.0/merchant-api-1.0';

    /** @var array<int, string> */
    private const ALIPAY_CONFIGURED_WAYS = ['ALI_QR', 'ALI_WAP', 'ALI_PC', 'QR_CASHIER'];

    /** @var array<int, string> */
    private const WXPAY_CONFIGURED_WAYS = ['WX_NATIVE', 'WX_H5', 'QR_CASHIER'];

    /** @var array<int, string> */
    private const BANK_CONFIGURED_WAYS = ['QR_CASHIER'];

    private ?JeepayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'jeepay_api',
        'name' => 'Jeepay聚合支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.jeequan.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造 Jeepay 支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付单仓库；未注入时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
    }

    /**
     * 初始化并校验当前 API profile 的通道配置。
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
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            $this->inputField('api_url', 'Jeepay HTTPS 接口地址', true, '例如：https://pay.example.com'),
            $this->inputField('mch_no', 'Jeepay 商户号', true),
            $this->inputField('app_id', 'Jeepay 应用 ID', true),
            ['type' => 'password', 'field' => 'api_key', 'title' => 'Jeepay MD5 接口密钥', 'value' => '', 'validate' => [['required' => true, 'message' => 'Jeepay MD5 接口密钥不能为空']]],
            $this->selectField('alipay_way_code', '支付宝配置产品 wayCode', 'ALI_QR', [
                'ALI_QR' => 'ALI_QR 支付宝二维码',
                'ALI_WAP' => 'ALI_WAP 支付宝手机网站',
                'ALI_PC' => 'ALI_PC 支付宝电脑网站',
                'QR_CASHIER' => 'QR_CASHIER Jeepay 托管收银台',
            ]),
            $this->selectField('alipay_pay_data_type', '支付宝网页承接类型', 'payUrl', [
                'payUrl' => 'HTTPS 跳转链接',
                'form' => '可信 HTTPS POST 表单',
            ]),
            $this->selectField('wxpay_way_code', '微信配置产品 wayCode', 'WX_NATIVE', [
                'WX_NATIVE' => 'WX_NATIVE 微信 Native',
                'WX_H5' => 'WX_H5 微信 H5',
                'QR_CASHIER' => 'QR_CASHIER Jeepay 托管收银台',
            ]),
            $this->selectField('bank_way_code', '银行卡配置产品 wayCode', 'QR_CASHIER', [
                'QR_CASHIER' => 'QR_CASHIER Jeepay 托管收银台',
            ]),
            [
                'type' => 'textarea',
                'field' => 'trusted_html_hosts',
                'title' => 'HTML 表单可信主机',
                'value' => 'openapi.alipay.com',
                'tip' => '每行或逗号分隔；form action 仅允许当前 Jeepay 主机或这里列出的 HTTPS 主机。',
            ],
            $this->inputField('alipay_oauth_app_id', '支付宝身份授权 AppID'),
            ['type' => 'textarea', 'field' => 'alipay_oauth_private_key', 'title' => '支付宝身份授权应用私钥', 'value' => ''],
            ['type' => 'textarea', 'field' => 'alipay_oauth_public_key', 'title' => '支付宝身份授权公钥', 'value' => ''],
            ['type' => 'switch', 'field' => 'alipay_oauth_sandbox', 'title' => '支付宝身份授权沙箱', 'value' => false],
            $this->inputField('wx_mp_app_id', '微信公众号 AppID'),
            ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            $this->inputField('wx_mini_app_id', '微信小程序 AppID'),
            ['type' => 'password', 'field' => 'wx_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            $this->inputField('wx_mini_launch_path', '微信小程序启动路径'),
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_JSAPI => '支付宝生活号 JSAPI',
                self::PRODUCT_WXPAY_MP => '微信公众号 JSAPI',
                self::PRODUCT_WXPAY_MINI => '微信小程序支付',
                self::PRODUCT_ALIPAY_CONFIGURED => '支付宝配置产品',
                self::PRODUCT_WXPAY_CONFIGURED => '微信配置产品',
                self::PRODUCT_BANK_CONFIGURED => '银行卡托管收银台',
            ]),
        ];
    }

    /**
     * 声明直接 JSAPI 产品的严格身份需求。
     *
     * 身份需求与最终选中的 handler 一致；配置型网页或二维码产品不会触发 JSAPI 授权。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；当前产品无需身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        if (!in_array($this->selectedHandler($order), ['jsapi', 'mini'], true)) {
            return null;
        }

        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        if ($payType === 'alipay') {
            $this->assertAlipayAppScope($payment);
            if ($this->requiredIdentity($payment, self::WAY_ALI_JSAPI, false) !== '') {
                return null;
            }

            $appId = $this->requiredConfig('alipay_oauth_app_id', '支付宝身份授权 AppID');
            $privateKey = $this->requiredConfig('alipay_oauth_private_key', '支付宝身份授权应用私钥');
            $publicKey = $this->requiredConfig('alipay_oauth_public_key', '支付宝身份授权公钥');

            return [
                'provider' => 'alipay',
                'product' => 'jsapi',
                'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
                'auth_type' => 'alipay_oauth',
                'identity_field' => 'buyer_id',
                'identity_aliases' => [],
                'app_id' => $appId,
                'scope' => 'auth_base',
                '_alipay_config' => [
                    'mode' => 'key',
                    'app_id' => $appId,
                    'private_key' => $privateKey,
                    'alipay_public_key' => $publicKey,
                    'sandbox' => $this->configBool('alipay_oauth_sandbox'),
                ],
                'message' => 'Jeepay ALI_JSAPI 需要当前支付宝应用作用域的 buyer_id',
            ];
        }

        if ($payType !== 'wxpay') {
            return null;
        }

        $mini = $this->isMiniIntent($payment);
        $wayCode = $mini ? self::WAY_WX_LITE : self::WAY_WX_JSAPI;
        $this->assertWechatAppScope($payment, $mini);
        if ($this->requiredIdentity($payment, $wayCode, false) !== '') {
            return null;
        }

        $appId = $this->requiredConfig($mini ? 'wx_mini_app_id' : 'wx_mp_app_id', $mini ? '微信小程序 AppID' : '微信公众号 AppID');
        $appSecret = $this->requiredConfig($mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret', $mini ? '微信小程序 AppSecret' : '微信公众号 AppSecret');

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
            'mini_launch_type' => $mini && strtolower(trim((string) ($order['_env'] ?? ''))) === 'wechat' ? 'url_link' : 'url_scheme',
            'env_version' => 'release',
            'message' => $mini
                ? 'Jeepay WX_LITE 仅接受当前小程序作用域的 mini_openid'
                : 'Jeepay WX_JSAPI 仅接受当前公众号作用域的 openid/sub_openid',
        ];
    }

    /**
     * 发起支付；选定产品后只请求一次，不切换 wayCode 重试。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        return $this->executeDirectPaymentProduct($order, $this->paymentHandlers($order), 'Jeepay');
    }

    /**
     * Jeepay 当前不支持主动查单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('Jeepay插件暂不支持主动查单', 40200);
    }

    /**
     * Jeepay 当前不支持主动关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('Jeepay插件暂不支持主动关单', 40200);
    }

    /**
     * 按 Jeepay payOrderId 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $refundNo = $this->requiredText($order['refund_no'] ?? '', 'Jeepay退款缺少 refund_no');
        $payNo = $this->requiredText($order['pay_no'] ?? '', 'Jeepay退款缺少 pay_no');
        $payOrderId = $this->requiredText($order['chan_order_no'] ?? '', 'Jeepay退款缺少 payOrderId（chan_order_no）');
        $refundAmount = $this->positiveCents($order['refund_amount'] ?? null, 'Jeepay退款金额');
        $payload = $this->baseRequestPayload() + [
            'mchRefundNo' => $refundNo,
            'payOrderId' => $payOrderId,
            'refundAmount' => $refundAmount,
            'currency' => 'cny',
        ];
        $reason = trim((string) ($order['refund_reason'] ?? ''));
        if ($reason !== '') {
            $payload['refundReason'] = mb_substr($reason, 0, 64, 'UTF-8');
        }
        $notifyUrl = trim((string) ($order['refund_callback_url'] ?? ''));
        if ($notifyUrl !== '') {
            $payload['notifyUrl'] = $notifyUrl;
        }

        try {
            $data = $this->client()->post('/api/refund/refundOrder', $payload);
        } catch (JeepaySdkException $e) {
            $this->throwSdkException($e, '退款');
        }

        try {
            $this->assertTextEquals($refundNo, $data['mchRefundNo'] ?? '', 'Jeepay退款响应 mchRefundNo 不匹配');
            if ($this->positiveCents($data['refundAmount'] ?? null, 'Jeepay退款响应金额') !== $refundAmount) {
                throw new PaymentUncertainException('Jeepay退款响应金额不匹配', 40200, ['refund_no' => $refundNo]);
            }
            if (array_key_exists('payAmount', $data)
                && $this->positiveCents($data['payAmount'], 'Jeepay退款响应原支付金额') !== (int) ($order['amount'] ?? 0)) {
                throw new PaymentUncertainException('Jeepay退款响应原支付金额不匹配', 40200, ['refund_no' => $refundNo]);
            }

            $state = $this->stateCode($data['state'] ?? null, 'Jeepay退款响应 state');
            if (in_array($state, ['3', '4'], true)) {
                throw new PaymentDefinitiveException('Jeepay退款已明确失败或关闭', 40200, [
                    'refund_no' => $refundNo,
                    'channel_status' => $state,
                ]);
            }
            $status = match ($state) {
                '2' => PaymentPluginStatusConstant::SUCCESS,
                '0', '1' => PaymentPluginStatusConstant::PENDING,
                default => PaymentPluginStatusConstant::UNKNOWN,
            };
            $channelRefundNo = $this->requiredText($data['refundOrderId'] ?? '', 'Jeepay退款响应缺少 refundOrderId');

            return [
                'status' => $status,
                'refund_no' => $refundNo,
                'pay_no' => $payNo,
                'refund_amount' => $refundAmount,
                'chan_refund_no' => $channelRefundNo,
                'channel_status' => $state,
                'message' => $status === PaymentPluginStatusConstant::SUCCESS ? 'Jeepay退款成功' : 'Jeepay退款状态已受理',
            ];
        } catch (PaymentDefinitiveException|PaymentUncertainException $e) {
            throw $e;
        } catch (PaymentException $e) {
            throw new PaymentUncertainException('Jeepay退款响应无法安全确认：' . $e->getMessage(), 40200, ['refund_no' => $refundNo]);
        }
    }

    /**
     * 解析并强关联 Jeepay 支付成功通知。
     *
     * 验签后校验商户、应用、请求时间、币种、金额、payOrderId、channelOrderNo 和 wayCode。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $this->verifiedFormPayload($request, 'Jeepay支付回调');
        $this->assertMerchantAndApp($payload, 'Jeepay支付回调');
        $this->assertNotifyReqTime($payload, 'Jeepay支付回调');
        if ($this->stateCode($payload['state'] ?? null, 'Jeepay支付回调 state') !== '2') {
            throw new PaymentException('Jeepay支付回调不是支付成功终态', 40200);
        }
        $this->assertCurrency($payload, 'Jeepay支付回调');

        $payNo = $this->requiredText($payload['mchOrderNo'] ?? '', 'Jeepay支付回调缺少 mchOrderNo');
        $payOrder = $this->payOrder($payNo, 'Jeepay支付回调');
        $amount = $this->positiveCents($payload['amount'] ?? null, 'Jeepay支付回调金额');
        if ($amount !== (int) $payOrder->pay_amount) {
            throw new PaymentException('Jeepay支付回调金额与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }

        $payOrderId = $this->requiredText($payload['payOrderId'] ?? '', 'Jeepay支付回调缺少 payOrderId');
        $channelOrderNo = $this->optionalText($payload['channelOrderNo'] ?? '') ?? '';
        $this->assertStoredReference((string) ($payOrder->channel_order_no ?? ''), $payOrderId, 'Jeepay支付回调 payOrderId 不匹配', $payNo);
        $this->assertStoredReference((string) ($payOrder->channel_trade_no ?? ''), $channelOrderNo, 'Jeepay支付回调 channelOrderNo 不匹配', $payNo);
        $this->assertStoredWayCode($payOrder, $payload['wayCode'] ?? '');

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $amount,
            'message' => 'Jeepay支付成功',
            'chan_order_no' => $payOrderId,
            'chan_trade_no' => $channelOrderNo,
            'channel_status' => '2',
            'paid_at' => $this->optionalMillis($payload['successTime'] ?? '', 'Jeepay支付回调 successTime'),
        ];
    }

    /**
     * 解析并强关联 Jeepay 独立退款通知。
     *
     * @param Request $request 退款通知请求
     * @param array<string, mixed> $refund 本地退款单上下文
     * @return array<string, mixed> 标准退款通知结果
     */
    public function refundNotify(Request $request, array $refund): array
    {
        $payload = $this->verifiedFormPayload($request, 'Jeepay退款回调');
        $this->assertMerchantAndApp($payload, 'Jeepay退款回调');
        $this->assertNotifyReqTime($payload, 'Jeepay退款回调');
        $this->assertCurrency($payload, 'Jeepay退款回调');

        $refundNo = $this->requiredText($refund['refund_no'] ?? '', 'Jeepay退款回调上下文缺少 refund_no');
        $payNo = $this->requiredText($refund['pay_no'] ?? '', 'Jeepay退款回调上下文缺少 pay_no');
        $refundAmount = $this->positiveCents($refund['refund_amount'] ?? null, 'Jeepay退款回调上下文金额');
        $this->assertTextEquals($refundNo, $payload['mchRefundNo'] ?? '', 'Jeepay退款回调 mchRefundNo 不匹配');
        if ($this->positiveCents($payload['refundAmount'] ?? null, 'Jeepay退款回调金额') !== $refundAmount) {
            throw new PaymentException('Jeepay退款回调金额不匹配', 40200, ['refund_no' => $refundNo]);
        }

        $payOrder = $this->payOrder($payNo, 'Jeepay退款回调');
        if ($this->positiveCents($payload['payAmount'] ?? null, 'Jeepay退款回调原支付金额') !== (int) $payOrder->pay_amount) {
            throw new PaymentException('Jeepay退款回调原支付金额不匹配', 40200, ['refund_no' => $refundNo]);
        }
        $payOrderId = $this->requiredText($payload['payOrderId'] ?? '', 'Jeepay退款回调缺少 payOrderId');
        $this->assertStoredReference((string) ($payOrder->channel_order_no ?? ''), $payOrderId, 'Jeepay退款回调 payOrderId 不匹配', $payNo, true);
        if (array_key_exists('channelOrderNo', $payload) && !is_scalar($payload['channelOrderNo'])) {
            throw new PaymentException('Jeepay退款回调 channelOrderNo 格式无效', 40200, ['refund_no' => $refundNo]);
        }

        $channelRefundNo = $this->requiredText($payload['refundOrderId'] ?? '', 'Jeepay退款回调缺少 refundOrderId');
        $storedRefundNo = trim((string) ($refund['chan_refund_no'] ?? ''));
        if ($storedRefundNo !== '' && !hash_equals($storedRefundNo, $channelRefundNo)) {
            throw new PaymentException('Jeepay退款回调 refundOrderId 不匹配', 40200, ['refund_no' => $refundNo]);
        }
        $state = $this->stateCode($payload['state'] ?? null, 'Jeepay退款回调 state');
        $status = match ($state) {
            '0', '1' => PaymentPluginStatusConstant::PENDING,
            '2' => PaymentPluginStatusConstant::SUCCESS,
            '3', '4' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => $state,
            'message' => $this->optionalText($payload['errMsg'] ?? '') ?: 'Jeepay退款状态通知',
        ];
    }

    /**
     * 返回渠道要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回渠道要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 返回退款渠道要求的成功应答。
     */
    public function refundNotifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回退款渠道要求的失败应答。
     */
    public function refundNotifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 构建当前订单可用的支付处理器。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, callable|array<string, mixed>> 支付场景与处理器映射
     */
    private function paymentHandlers(array $order): array
    {
        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        $handlers = [];
        if ($payType === 'alipay') {
            $handlers['jsapi'] = [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI],
                'handler' => fn (): array => $this->productPay($order, self::PRODUCT_ALIPAY_JSAPI, self::WAY_ALI_JSAPI),
            ];
        } elseif ($payType === 'wxpay') {
            $mini = $this->isMiniIntent($payment);
            $product = $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP;
            $wayCode = $mini ? self::WAY_WX_LITE : self::WAY_WX_JSAPI;
            $handlers[$mini ? 'mini' : 'jsapi'] = [
                'products' => ['wxpay' => $product],
                'handler' => fn (): array => $this->productPay($order, $product, $wayCode),
            ];
        }

        $configuredProduct = match ($payType) {
            'alipay' => self::PRODUCT_ALIPAY_CONFIGURED,
            'wxpay' => self::PRODUCT_WXPAY_CONFIGURED,
            'bank' => self::PRODUCT_BANK_CONFIGURED,
        };
        $wayCode = $this->configuredWayCode($payType);
        foreach ($this->handlerNamesForWayCode($wayCode) as $handlerName) {
            $handlers[$handlerName] = [
                'products' => [$payType => $configuredProduct],
                'handler' => fn (): array => $this->productPay($order, $configuredProduct, $wayCode),
            ];
        }

        return $handlers;
    }

    /**
     * 返回当前订单实际会选择的 handler。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 当前订单实际选择的处理器名称
     */
    private function selectedHandler(array $order): string
    {
        $handlers = $this->directPaymentUsableHandlers($order, $this->paymentHandlers($order));
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));

        return (string) ($candidates[0] ?? '');
    }

    /**
     * Jeepay 统一下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 平台产品编码
     * @param string $wayCode Jeepay 支付方式编码
     * @return array<string, mixed> 标准支付结果
     */
    private function productPay(array $order, string $product, string $wayCode): array
    {
        $payType = $this->payTypeCode($order);
        $channelExtra = $this->channelExtra($order, $wayCode);
        $payload = $this->baseRequestPayload() + [
            'mchOrderNo' => $this->requiredText($order['pay_no'] ?? '', 'Jeepay下单缺少 pay_no'),
            'wayCode' => $wayCode,
            'amount' => $this->positiveCents($order['amount'] ?? null, 'Jeepay下单金额'),
            'currency' => 'cny',
            'clientIp' => $this->requiredText($order['client_ip'] ?? '', 'Jeepay下单缺少 client_ip'),
            'subject' => mb_substr($this->requiredText($order['subject'] ?? '', 'Jeepay下单缺少 subject'), 0, 64, 'UTF-8'),
            'body' => mb_substr($this->requiredText($order['subject'] ?? '', 'Jeepay下单缺少 subject'), 0, 256, 'UTF-8'),
            'notifyUrl' => $this->requiredText($order['callback_url'] ?? '', 'Jeepay下单缺少 callback_url'),
        ];
        $returnUrl = trim((string) ($order['return_url'] ?? ''));
        if ($returnUrl !== '') {
            $payload['returnUrl'] = $returnUrl;
        }
        if ($channelExtra !== []) {
            try {
                $payload['channelExtra'] = json_encode($channelExtra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new PaymentDefinitiveException('Jeepay channelExtra 编码失败', 40200);
            }
        }

        try {
            $data = $this->client()->post('/api/pay/unifiedOrder', $payload);
        } catch (JeepaySdkException $e) {
            $this->throwSdkException($e, '下单');
        }

        try {
            return $this->buildPayResult($order, $payType, $product, $wayCode, $data);
        } catch (PaymentDefinitiveException|PaymentUncertainException $e) {
            throw $e;
        } catch (PaymentException $e) {
            throw new PaymentUncertainException('Jeepay下单响应无法安全承接：' . $e->getMessage(), 40200, [
                'pay_no' => (string) ($order['pay_no'] ?? ''),
                'way_code' => $wayCode,
            ]);
        }
    }

    /**
     * 构建渠道扩展参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $wayCode Jeepay 支付方式编码
     * @return array<string, scalar> 渠道扩展参数
     */
    private function channelExtra(array $order, string $wayCode): array
    {
        $payment = $this->paymentPayload($order);

        return match ($wayCode) {
            self::WAY_ALI_JSAPI => ['buyerUserId' => $this->requiredIdentity($payment, $wayCode)],
            self::WAY_WX_JSAPI, self::WAY_WX_LITE => ['openid' => $this->requiredIdentity($payment, $wayCode)],
            'QR_CASHIER', 'ALI_QR', 'WX_NATIVE' => ['payDataType' => 'codeUrl'],
            'ALI_WAP', 'ALI_PC' => ['payDataType' => $this->configText('alipay_pay_data_type') ?: 'payUrl'],
            default => [],
        };
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 平台支付方式编码
     * @param string $product 平台产品编码
     * @param string $wayCode Jeepay 支付方式编码
     * @param array<string, mixed> $data Jeepay 下单响应
     * @return array<string, mixed> 标准支付结果
     */
    private function buildPayResult(array $order, string $payType, string $product, string $wayCode, array $data): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', 'Jeepay下单缺少 pay_no');
        $this->assertTextEquals($payNo, $data['mchOrderNo'] ?? '', 'Jeepay下单响应 mchOrderNo 不匹配');
        $payOrderId = $this->requiredText($data['payOrderId'] ?? '', 'Jeepay下单响应缺少 payOrderId');
        $orderState = $this->stateCode($data['orderState'] ?? null, 'Jeepay下单响应 orderState');
        if ($orderState === '2') {
            return $this->successfulPaymentResult($order, [
                'paid_amount' => (int) ($order['amount'] ?? 0),
                'pay_type' => $payType,
                'pay_product' => $product,
                'pay_action' => 'unifiedOrder',
                'chan_order_no' => $payOrderId,
                'chan_trade_no' => '',
                'channel_context' => [
                    'api_profile' => self::API_PROFILE,
                    'way_code' => $wayCode,
                ],
            ]);
        }
        if (in_array($orderState, ['3', '4', '5', '6'], true)) {
            throw new PaymentDefinitiveException('Jeepay下单返回支付失败或已终止状态', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $orderState,
            ]);
        }
        if (!in_array($orderState, ['0', '1'], true)) {
            throw new PaymentUncertainException('Jeepay下单返回未知 orderState', 40200, [
                'pay_no' => $payNo,
                'channel_status' => $orderState,
            ]);
        }

        $payDataType = $this->requiredText($data['payDataType'] ?? '', 'Jeepay下单响应缺少 payDataType');
        $payData = $data['payData'] ?? null;
        if (!is_string($payData) || trim($payData) === '') {
            throw new PaymentUncertainException('Jeepay下单已创建订单，但 payData 为空或类型无效', 40200, ['pay_no' => $payNo]);
        }
        [$page, $params] = $this->presentation($payDataType, $payData, $wayCode);

        return $this->payResult($order, $page, $payType, $product, $wayCode, $payOrderId, $params);
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 平台产品编码
     * @param string $wayCode Jeepay 支付方式编码
     * @param string $payOrderId Jeepay 支付订单号
     * @param array<string, mixed> $params 前端承接参数
     * @return array<string, mixed> 标准支付结果
     */
    private function payResult(
        array $order,
        string $page,
        string $payType,
        string $product,
        string $wayCode,
        string $payOrderId,
        array $params
    ): array {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'unifiedOrder',
            'pay_params' => $params,
            'chan_order_no' => $payOrderId,
            'chan_trade_no' => '',
            'channel_context' => [
                'api_profile' => self::API_PROFILE,
                'way_code' => $wayCode,
            ],
        ]);
    }

    /**
     * 构建支付承接参数。
     *
     * @param string $payDataType Jeepay 支付数据类型
     * @param string $payData Jeepay 支付数据
     * @param string $wayCode Jeepay 支付方式编码
     * @return array{0:string,1:array<string,mixed>}
     */
    private function presentation(string $payDataType, string $payData, string $wayCode): array
    {
        return match ($payDataType) {
            'codeUrl' => $this->codeUrlPresentation($payData, $wayCode),
            'payUrl' => $this->payUrlPresentation($payData, $wayCode),
            'form' => $this->formPresentation($payData, $wayCode),
            'aliapp' => $this->alipayJsapiPresentation($payData, $wayCode),
            'wxapp' => $this->wechatPresentation($payData, $wayCode),
            'codeImgUrl' => throw new PaymentUncertainException('Jeepay返回 codeImgUrl，当前二维码承接器不支持图片 URL', 40200),
            'ysfapp', 'none' => throw new PaymentUncertainException('Jeepay返回当前插件未实现的 payDataType：' . $payDataType, 40200),
            default => throw new PaymentUncertainException('Jeepay返回未知 payDataType：' . $payDataType, 40200),
        };
    }

    /**
     * 构建二维码承接参数。
     *
     * @param string $payData 二维码内容
     * @param string $wayCode Jeepay 支付方式编码
     * @return array{0:string,1:array<string,string>}
     */
    private function codeUrlPresentation(string $payData, string $wayCode): array
    {
        if (!in_array($wayCode, ['QR_CASHIER', 'ALI_QR', 'WX_NATIVE'], true)) {
            throw new PaymentUncertainException('Jeepay codeUrl 与 wayCode 不匹配', 40200);
        }

        return ['qrcode', ['qrcode' => trim($payData)]];
    }

    /**
     * 构建跳转地址承接参数。
     *
     * @param string $payData 跳转地址
     * @param string $wayCode Jeepay 支付方式编码
     * @return array{0:string,1:array<string,string>}
     */
    private function payUrlPresentation(string $payData, string $wayCode): array
    {
        if (!in_array($wayCode, ['ALI_WAP', 'ALI_PC', 'WX_H5'], true) || !$this->isTrustedHttpsUrl($payData, false)) {
            throw new PaymentUncertainException('Jeepay payUrl 与 wayCode 不匹配或不是 HTTPS URL', 40200);
        }

        return ['jump', ['url' => trim($payData)]];
    }

    /**
     * 构建表单承接参数。
     *
     * @param string $payData 表单 HTML
     * @param string $wayCode Jeepay 支付方式编码
     * @return array{0:string,1:array<string,string>}
     */
    private function formPresentation(string $payData, string $wayCode): array
    {
        if (!in_array($wayCode, ['ALI_WAP', 'ALI_PC'], true)) {
            throw new PaymentUncertainException('Jeepay form 与 wayCode 不匹配', 40200);
        }

        return ['html', ['html' => $this->sanitizePostForm($payData)]];
    }

    /**
     * 构建支付宝 JSAPI 承接参数。
     *
     * @param string $payData 支付宝支付数据
     * @param string $wayCode Jeepay 支付方式编码
     * @return array{0:string,1:array<string,string>}
     */
    private function alipayJsapiPresentation(string $payData, string $wayCode): array
    {
        if ($wayCode !== self::WAY_ALI_JSAPI) {
            throw new PaymentUncertainException('Jeepay aliapp 与 wayCode 不匹配', 40200);
        }
        $object = $this->jsonObject($payData, 'Jeepay ALI_JSAPI payData');
        $tradeNo = $this->requiredText($object['alipayTradeNo'] ?? '', 'Jeepay ALI_JSAPI payData 缺少 alipayTradeNo');

        return ['jsapi', ['tradeNO' => $tradeNo]];
    }

    /**
     * 构建微信支付承接参数。
     *
     * @param string $payData 微信支付数据
     * @param string $wayCode Jeepay 支付方式编码
     * @return array{0:string,1:array<string,mixed>}
     */
    private function wechatPresentation(string $payData, string $wayCode): array
    {
        if (!in_array($wayCode, [self::WAY_WX_JSAPI, self::WAY_WX_LITE], true)) {
            throw new PaymentUncertainException('Jeepay wxapp 与 wayCode 不匹配', 40200);
        }
        $params = $this->wxPayParams($this->jsonObject($payData, 'Jeepay微信 payData'));
        if ($wayCode === self::WAY_WX_JSAPI) {
            return ['jsapi', $params];
        }

        return ['page', [
            '_page' => 'wechatMini',
            'request_payment' => $params,
        ]];
    }

    /**
     * 构建微信前端调起参数。
     *
     * @param array<string, mixed> $object Jeepay 微信支付数据
     * @return array<string, string> 微信前端调起参数
     */
    private function wxPayParams(array $object): array
    {
        $result = [];
        foreach (['appId', 'timeStamp', 'nonceStr', 'package', 'signType', 'paySign'] as $field) {
            $result[$field] = $this->requiredText($object[$field] ?? '', 'Jeepay微信 payData 缺少 ' . $field);
        }

        return $result;
    }

    /**
     * 仅承接一个可信 HTTPS POST 表单，并重建为最小转义页面。
     *
     * 原始 HTML 不直接返回前端：禁止文档实体、多个表单、非 POST、非白名单主机和
     * 非 hidden 输入，并对 action、name、value 重新转义，收窄 XSS 与外部提交风险。
     *
     * @param string $html Jeepay 表单 HTML
     * @return string 重建后的最小自动提交页面
     */
    private function sanitizePostForm(string $html): string
    {
        if (stripos($html, '<!doctype') !== false || stripos($html, '<!entity') !== false) {
            throw new PaymentUncertainException('Jeepay form 包含不允许的文档声明', 40200);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $document->getElementsByTagName('form')->length !== 1) {
            throw new PaymentUncertainException('Jeepay form 必须且只能包含一个表单', 40200);
        }

        $form = $document->getElementsByTagName('form')->item(0);
        if (!$form instanceof DOMElement || strtolower(trim($form->getAttribute('method'))) !== 'post') {
            throw new PaymentUncertainException('Jeepay form 只允许 POST', 40200);
        }
        $action = trim($form->getAttribute('action'));
        if (!$this->isTrustedHttpsUrl($action, true)) {
            throw new PaymentUncertainException('Jeepay form action 不是可信 HTTPS 上游', 40200);
        }
        if ($form->getElementsByTagName('textarea')->length > 0 || $form->getElementsByTagName('select')->length > 0) {
            throw new PaymentUncertainException('Jeepay form 包含不允许的交互字段', 40200);
        }

        $fields = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            if (!$input instanceof DOMElement) {
                continue;
            }
            $type = strtolower(trim($input->getAttribute('type')) ?: 'text');
            if (in_array($type, ['submit', 'button'], true)) {
                continue;
            }
            if ($type !== 'hidden') {
                throw new PaymentUncertainException('Jeepay form 只允许 hidden 输入字段', 40200);
            }
            $name = trim($input->getAttribute('name'));
            if ($name === '') {
                throw new PaymentUncertainException('Jeepay form hidden 字段缺少 name', 40200);
            }
            $fields[] = [$name, $input->getAttribute('value')];
        }

        $escapedAction = htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inputs = '';
        foreach ($fields as [$name, $value]) {
            $inputs .= '<input type="hidden" name="'
                . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" value="'
                . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">';
        }

        return '<!doctype html><html><head><meta charset="UTF-8"></head><body>'
            . '<form id="jeepay-submit" method="post" action="' . $escapedAction . '">' . $inputs . '</form>'
            . '<script>document.getElementById("jeepay-submit").submit();</script>'
            . '<noscript><button type="submit" form="jeepay-submit">继续支付</button></noscript>'
            . '</body></html>';
    }

    /**
     * 验签并读取表单通知。
     *
     * @param Request $request 通知请求
     * @param string $scene 通知场景说明
     * @return array<string, mixed> 已验签表单字段
     */
    private function verifiedFormPayload(Request $request, string $scene): array
    {
        $payload = $request->post();
        if (!is_array($payload) || $payload === []) {
            throw new PaymentException($scene . '必须使用 form POST', 40200);
        }
        if (!$this->client()->verify($payload)) {
            throw new PaymentException($scene . '验签失败', 40200);
        }

        return $payload;
    }

    /**
     * 构建上游基础请求参数。
     *
     * @return array<string, string>
     */
    private function baseRequestPayload(): array
    {
        return [
            'mchNo' => $this->requiredConfig('mch_no', 'Jeepay 商户号'),
            'appId' => $this->requiredConfig('app_id', 'Jeepay 应用 ID'),
            'reqTime' => (string) ((int) floor(microtime(true) * 1000)),
            'version' => '1.0',
            'signType' => 'MD5',
        ];
    }

    /**
     * 校验通知中的商户和应用身份。
     *
     * @param array<string, mixed> $payload 通知字段
     * @param string $scene 通知场景说明
     * @return void
     */
    private function assertMerchantAndApp(array $payload, string $scene): void
    {
        $this->assertTextEquals($this->requiredConfig('mch_no', 'Jeepay 商户号'), $payload['mchNo'] ?? '', $scene . ' mchNo 不匹配');
        $this->assertTextEquals($this->requiredConfig('app_id', 'Jeepay 应用 ID'), $payload['appId'] ?? '', $scene . ' appId 不匹配');
    }

    /**
     * 校验通知币种。
     *
     * @param array<string, mixed> $payload 通知字段
     * @param string $scene 通知场景说明
     * @return void
     */
    private function assertCurrency(array $payload, string $scene): void
    {
        if (!hash_equals('cny', strtolower($this->requiredText($payload['currency'] ?? '', $scene . '缺少 currency')))) {
            throw new PaymentException($scene . ' currency 不是 cny', 40200);
        }
    }

    /**
     * 校验通知请求时间。
     *
     * @param array<string, mixed> $payload 通知字段
     * @param string $scene 通知场景说明
     * @return void
     */
    private function assertNotifyReqTime(array $payload, string $scene): void
    {
        $reqTime = $this->requiredText($payload['reqTime'] ?? '', $scene . '缺少 reqTime');
        if (preg_match('/^\d{13}$/', $reqTime) !== 1) {
            throw new PaymentException($scene . ' reqTime 不是 13 位毫秒时间戳', 40200);
        }
    }

    private function assertTextEquals(string $expected, mixed $actual, string $message): void
    {
        $actualText = $this->requiredText($actual, $message);
        if (!hash_equals($expected, $actualText)) {
            throw new PaymentException($message, 40200);
        }
    }

    /**
     * 校验通知渠道引用与本地已保存值一致。
     *
     * @param string $stored 本地已保存渠道引用
     * @param string $actual 通知渠道引用
     * @param string $message 校验失败消息
     * @param string $payNo 平台支付单号
     * @param bool $requireStored 是否要求本地引用必须存在
     * @return void
     */
    private function assertStoredReference(
        string $stored,
        string $actual,
        string $message,
        string $payNo,
        bool $requireStored = false
    ): void {
        $stored = trim($stored);
        if (($requireStored && $stored === '') || ($stored !== '' && !hash_equals($stored, $actual))) {
            throw new PaymentException($message, 40200, ['pay_no' => $payNo]);
        }
    }

    /**
     * 校验通知 wayCode 与下单产品快照一致。
     *
     * @param PayOrder $payOrder 本地支付单
     * @param mixed $actualWayCode 通知支付方式编码
     * @return void
     */
    private function assertStoredWayCode(PayOrder $payOrder, mixed $actualWayCode): void
    {
        $actual = $this->requiredText($actualWayCode, 'Jeepay支付回调缺少 wayCode');
        $extJson = is_array($payOrder->ext_json ?? null) ? $payOrder->ext_json : [];
        $paymentContext = is_array($extJson['payment_context'] ?? null) ? $extJson['payment_context'] : [];
        $channelContext = is_array($paymentContext['channel_context'] ?? null)
            ? $paymentContext['channel_context']
            : [];
        $stored = trim((string) ($channelContext['way_code'] ?? ''));
        if ($stored !== '' && !hash_equals($stored, $actual)) {
            throw new PaymentException('Jeepay支付回调 wayCode 与下单产品不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
    }

    /**
     * 读取并校验当前通道的支付单。
     *
     * @param string $payNo 平台支付单号
     * @param string $scene 通知场景说明
     * @return PayOrder
     */
    private function payOrder(string $payNo, string $scene): PayOrder
    {
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException($scene . '对应的支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException($scene . '支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }

        return $payOrder;
    }

    /**
     * 构建必需的用户身份声明。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $wayCode Jeepay 支付方式编码
     * @param bool $throw 身份缺失时是否抛出异常
     * @return string 精确作用域的渠道用户身份
     */
    private function requiredIdentity(array $payment, string $wayCode, bool $throw = true): string
    {
        $identity = '';
        if ($wayCode === self::WAY_ALI_JSAPI) {
            $identity = trim((string) ($payment['buyer_id'] ?? ''));
        } elseif ($wayCode === self::WAY_WX_LITE) {
            $identity = trim((string) ($payment['mini_openid'] ?? ''));
        } elseif ($wayCode === self::WAY_WX_JSAPI) {
            $openid = trim((string) ($payment['openid'] ?? ''));
            $subOpenid = trim((string) ($payment['sub_openid'] ?? ''));
            if ($openid !== '' && $subOpenid !== '' && !hash_equals($openid, $subOpenid)) {
                throw new PaymentDefinitiveException('Jeepay WX_JSAPI openid 与 sub_openid 不一致', 40200);
            }
            $identity = $openid !== '' ? $openid : $subOpenid;
        }

        if ($identity === '' && $throw) {
            throw new PaymentDefinitiveException('Jeepay ' . $wayCode . ' 缺少严格匹配的支付身份', 40200);
        }

        return $identity;
    }

    /**
     * 校验支付宝应用身份作用域。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @return void
     */
    private function assertAlipayAppScope(array $payment): void
    {
        $this->assertAppScope($payment, $this->configText('alipay_oauth_app_id'), ['op_app_id', 'sub_appid'], 'Jeepay支付宝身份 AppID 作用域不一致');
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
        $this->assertAppScope(
            $payment,
            $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id'),
            $mini ? ['mini_app_id', 'sub_appid'] : ['sub_appid'],
            $mini ? 'Jeepay微信小程序 AppID 作用域不一致' : 'Jeepay微信公众号 AppID 作用域不一致'
        );
    }

    /**
     * 校验应用身份作用域。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $configured 当前产品配置的应用 ID
     * @param array<int, string> $fields 可接受的应用 ID 字段
     * @param string $message 校验失败消息
     * @return void
     */
    private function assertAppScope(array $payment, string $configured, array $fields, string $message): void
    {
        if ($configured === '') {
            return;
        }
        foreach ($fields as $field) {
            $provided = trim((string) ($payment[$field] ?? ''));
            if ($provided !== '' && !hash_equals($configured, $provided)) {
                throw new PaymentDefinitiveException($message, 40200);
            }
        }
    }

    /**
     * 判断订单是否明确要求小程序支付。
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

        return trim((string) ($payment['mini_openid'] ?? '')) !== ''
            || trim((string) ($payment['mini_app_id'] ?? '')) !== ''
            || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 读取标准支付载体参数。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return array<string, mixed> 支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        $extra = is_array($order['extra'] ?? null) ? $order['extra'] : [];
        return is_array($extra['payment'] ?? null) ? $extra['payment'] : [];
    }

    private function payTypeCode(array $order): string
    {
        $payType = trim((string) ($order['pay_type_code'] ?? ''));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentDefinitiveException('Jeepay不支持当前支付方式', 40200);
        }

        return $payType;
    }

    private function configuredWayCode(string $payType): string
    {
        return match ($payType) {
            'alipay' => $this->configText('alipay_way_code') ?: 'ALI_QR',
            'wxpay' => $this->configText('wxpay_way_code') ?: 'WX_NATIVE',
            'bank' => $this->configText('bank_way_code') ?: 'QR_CASHIER',
            default => throw new PaymentDefinitiveException('Jeepay支付方式无效', 40200),
        };
    }

    /**
     * 获取支付产品对应的处理器名称。
     *
     * @param string $wayCode Jeepay 支付方式编码
     * @return array<int, string>
     */
    private function handlerNamesForWayCode(string $wayCode): array
    {
        return match ($wayCode) {
            'QR_CASHIER', 'ALI_QR', 'WX_NATIVE' => ['qrcode'],
            'ALI_WAP', 'WX_H5' => ['h5', 'jump'],
            'ALI_PC' => ['web', 'jump'],
            default => [],
        };
    }

    /**
     * 将已保存的兼容产品开关值规范化为稳定业务产品。
     *
     * @return array<int, string>
     */
    private function enabledProducts(): array
    {
        $configured = $this->getConfig('enabled_products', null);
        if (!is_array($configured)) {
            return [
                self::PRODUCT_ALIPAY_JSAPI,
                self::PRODUCT_WXPAY_MP,
                self::PRODUCT_WXPAY_MINI,
                self::PRODUCT_ALIPAY_CONFIGURED,
                self::PRODUCT_WXPAY_CONFIGURED,
                self::PRODUCT_BANK_CONFIGURED,
            ];
        }
        $mapping = [
            'ALI_JSAPI' => [self::PRODUCT_ALIPAY_JSAPI],
            'WX_JSAPI' => [self::PRODUCT_WXPAY_MP, self::PRODUCT_WXPAY_MINI],
            'alipay_way_code' => [self::PRODUCT_ALIPAY_CONFIGURED],
            'wxpay_way_code' => [self::PRODUCT_WXPAY_CONFIGURED],
            'bank_way_code' => [self::PRODUCT_BANK_CONFIGURED],
        ];
        $products = [];
        foreach ($configured as $product) {
            $text = trim((string) $product);
            foreach ($mapping[$text] ?? [$text] as $stableProduct) {
                $products[] = $stableProduct;
            }
        }

        return array_values(array_unique($products));
    }

    private function validateConfiguration(): void
    {
        foreach (['mch_no' => 'Jeepay 商户号', 'app_id' => 'Jeepay 应用 ID'] as $field => $label) {
            $this->requiredConfig($field, $label);
        }
        new JeepayClient([
            'api_url' => $this->configText('api_url'),
            'api_key' => $this->configText('api_key'),
        ]);

        $this->assertConfiguredWay('alipay_way_code', self::ALIPAY_CONFIGURED_WAYS, '支付宝');
        $this->assertConfiguredWay('wxpay_way_code', self::WXPAY_CONFIGURED_WAYS, '微信');
        $this->assertConfiguredWay('bank_way_code', self::BANK_CONFIGURED_WAYS, '银行卡');
        if (!in_array($this->configText('alipay_pay_data_type') ?: 'payUrl', ['payUrl', 'form'], true)) {
            throw new PaymentException('Jeepay支付宝网页承接类型配置无效', 40200);
        }
        $supported = [
            self::PRODUCT_ALIPAY_JSAPI,
            self::PRODUCT_WXPAY_MP,
            self::PRODUCT_WXPAY_MINI,
            self::PRODUCT_ALIPAY_CONFIGURED,
            self::PRODUCT_WXPAY_CONFIGURED,
            self::PRODUCT_BANK_CONFIGURED,
        ];
        $products = $this->enabledProducts();
        if ($products === [] || array_diff($products, $supported) !== []) {
            throw new PaymentException('Jeepay已开通产品配置无效', 40200);
        }
    }

    /**
     * 校验支付产品是否已配置。
     *
     * @param string $field wayCode 配置字段名
     * @param array<int, string> $allowed 允许的 wayCode
     * @param string $label 支付方式说明
     * @return void
     */
    private function assertConfiguredWay(string $field, array $allowed, string $label): void
    {
        $value = $this->configText($field);
        if ($value === '') {
            $value = $allowed[0];
        }
        if (!in_array($value, $allowed, true)) {
            throw new PaymentException('Jeepay' . $label . ' wayCode 配置无效', 40200);
        }
    }

    /**
     * 按 SDK 异常确定性转换支付异常。
     *
     * @param JeepaySdkException $e SDK 异常
     * @param string $operation 业务动作说明
     * @return never
     */
    private function throwSdkException(JeepaySdkException $e, string $operation): never
    {
        $data = $e->channelErrorCode() === '' ? [] : ['channel_error_code' => $e->channelErrorCode()];
        if ($e->isUncertain()) {
            throw new PaymentUncertainException('Jeepay' . $operation . '结果不确定：' . $e->getMessage(), 40200, $data);
        }

        throw new PaymentDefinitiveException('Jeepay' . $operation . '被明确拒绝：' . $e->getMessage(), 40200, $data);
    }

    /**
     * 将 JSON 对象解码为关联数组。
     *
     * @param string $json JSON 文本
     * @param string $field 字段说明
     * @return array<string, mixed> JSON 对象
     */
    private function jsonObject(string $json, string $field): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentUncertainException($field . ' 不是合法 JSON 对象', 40200);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new PaymentUncertainException($field . ' 不是 JSON 对象', 40200);
        }

        return $decoded;
    }

    /**
     * 校验支付地址使用无凭据、无片段的 HTTPS URL。
     *
     * 表单 action 还必须命中可信主机白名单；普通跳转地址只校验 HTTPS 结构。
     *
     * @param string $url 支付地址
     * @param bool $checkHostAllowlist 是否校验可信主机白名单
     * @return bool 地址是否可信
     */
    private function isTrustedHttpsUrl(string $url, bool $checkHostAllowlist): bool
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if (!$checkHostAllowlist) {
            return true;
        }

        return in_array(strtolower((string) $parts['host']), $this->trustedHtmlHosts(), true);
    }

    /**
     * 获取受信任的支付页面主机。
     *
     * @return array<int, string>
     */
    private function trustedHtmlHosts(): array
    {
        $hosts = [];
        $apiHost = parse_url($this->configText('api_url'), PHP_URL_HOST);
        if (is_string($apiHost) && $apiHost !== '') {
            $hosts[] = strtolower($apiHost);
        }
        foreach (preg_split('/[\s,]+/', $this->configText('trusted_html_hosts')) ?: [] as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            $host = str_contains($entry, '://') ? parse_url($entry, PHP_URL_HOST) : $entry;
            if (is_string($host) && preg_match('/^[a-z0-9.-]+$/', $host) === 1) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function stateCode(mixed $value, string $field): string
    {
        $state = $this->requiredText($value, $field . ' 缺失');
        if (preg_match('/^\d+$/', $state) !== 1) {
            throw new PaymentException($field . ' 格式无效', 40200);
        }

        return $state;
    }

    private function positiveCents(mixed $value, string $field): int
    {
        if (!is_scalar($value)) {
            throw new PaymentException($field . '格式无效', 40200);
        }
        $text = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/', $text) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return (int) $text;
    }

    private function requiredText(mixed $value, string $message): string
    {
        if (!is_scalar($value)) {
            throw new PaymentException($message, 40200);
        }
        $text = trim((string) $value);
        if ($text === '') {
            throw new PaymentException($message, 40200);
        }

        return $text;
    }

    private function optionalText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            throw new PaymentException('Jeepay可选文本字段格式无效', 40200);
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function optionalMillis(mixed $value, string $field): ?string
    {
        $text = $this->optionalText($value);
        if ($text !== null && preg_match('/^\d{13}$/', $text) !== 1) {
            throw new PaymentException($field . ' 不是 13 位毫秒时间戳', 40200);
        }

        return $text;
    }

    private function requiredConfig(string $key, string $label): string
    {
        $value = $this->configText($key);
        if ($value === '') {
            throw new PaymentDefinitiveException($label . '不能为空', 40200);
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

    /**
     * 构建输入框配置项。
     *
     * @param string $field 配置字段名
     * @param string $title 配置项标题
     * @param bool $required 是否必填
     * @param string $placeholder 占位提示
     * @return array<string, mixed> 输入框配置
     */
    private function inputField(string $field, string $title, bool $required = false, string $placeholder = ''): array
    {
        $schema = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => ''];
        if ($placeholder !== '') {
            $schema['props'] = ['placeholder' => $placeholder];
        }
        if ($required) {
            $schema['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $schema;
    }

    /**
     * 构建下拉选择配置项。
     *
     * @param string $field 配置字段名
     * @param string $title 配置项标题
     * @param string $value 默认值
     * @param array<string, string> $options 选项值与标签
     * @return array<string, mixed> 下拉选择配置
     */
    private function selectField(string $field, string $title, string $value, array $options): array
    {
        return [
            'type' => 'select',
            'field' => $field,
            'title' => $title,
            'value' => $value,
            'options' => array_map(
                static fn (string $optionValue, string $label): array => ['label' => $label, 'value' => $optionValue],
                array_keys($options),
                array_values($options)
            ),
        ];
    }

    /**
     * 获取当前通道的 Jeepay 客户端。
     *
     * @return JeepayClient
     */
    private function client(): JeepayClient
    {
        if ($this->client === null) {
            $this->client = new JeepayClient([
                'api_url' => $this->configText('api_url'),
                'api_key' => $this->configText('api_key'),
            ]);
        }

        return $this->client;
    }
}
