<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\allinpay\AllinpayClient;
use app\common\sdk\allinpay\AllinpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use DateTimeImmutable;
use support\Request;
use support\Response;

/**
 * 通联收银宝 API 插件。
 *
 * 对接收银宝统一下单、托管收银台、查单、关单和退款接口，并将支付宝、微信、
 * QQ 钱包及云闪付的渠道差异归一化为平台支付结果。需要用户身份的 JSAPI 产品
 * 仅声明身份要求，授权流程由平台身份服务负责。
 */
class AllinpayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PAY_URL = 'https://vsp.allinpay.com/apiweb/unitorder/pay';
    private const QUERY_URL = 'https://vsp.allinpay.com/apiweb/tranx/query';
    private const CLOSE_URL = 'https://vsp.allinpay.com/apiweb/tranx/close';
    private const REFUND_URL = 'https://vsp.allinpay.com/apiweb/tranx/refund';
    private const AUTH_CODE_URL = 'https://vsp.allinpay.com/apiweb/unitorder/authcodetouserid';
    private const CASHIER_URL = 'https://syb.allinpay.com/apiweb/h5unionpay/unionorder';

    private const TEST_PAY_URL = 'https://syb-test.allinpay.com/apiweb/unitorder/pay';
    private const TEST_QUERY_URL = 'https://syb-test.allinpay.com/apiweb/tranx/query';
    private const TEST_CLOSE_URL = 'https://syb-test.allinpay.com/apiweb/tranx/close';
    private const TEST_REFUND_URL = 'https://syb-test.allinpay.com/apiweb/tranx/refund';
    private const TEST_AUTH_CODE_URL = 'https://syb-test.allinpay.com/apiweb/unitorder/authcodetouserid';
    private const TEST_CASHIER_URL = 'https://syb-test.allinpay.com/apiweb/h5unionpay/unionorder';

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_QQPAY_SCAN = 'qqpay_scan';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_BANK_JSAPI = 'bank_jsapi';
    private const PRODUCT_CASHIER = 'cashier';

    /**
     * @var array<string, string>
     */
    private const PRODUCT_OPTIONS = [
        self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
        self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
        self::PRODUCT_WXPAY_SCAN => '微信扫码',
        self::PRODUCT_WXPAY_JSAPI => '微信公众号/小程序',
        self::PRODUCT_QQPAY_SCAN => 'QQ 钱包扫码',
        self::PRODUCT_BANK_SCAN => '云闪付扫码',
        self::PRODUCT_BANK_JSAPI => '云闪付 JSAPI',
        self::PRODUCT_CASHIER => 'H5 托管收银台',
    ];

    /**
     * @var array<int, string>
     */
    private const PENDING_STATUSES = ['2000', '2008'];

    private ?AllinpayClient $client = null;

    /**
     * 插件元信息。
     *
     * 配置表单由 getConfigSchema() 动态补充，避免在静态元信息中固化产品开通项。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'allinpay_api',
        'name' => '通联支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'qqpay', 'bank'],
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
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户号',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户号不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '收银宝应用 ID',
                'value' => '',
                'validate' => [['required' => true, 'message' => '应用 ID 不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '通联 RSA 公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [['required' => true, 'message' => '通联公钥不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户 RSA 私钥',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [['required' => true, 'message' => '商户私钥不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'wx_mp_app_id',
                'title' => '微信公众号 AppID',
                'value' => '',
            ],
            [
                'type' => 'password',
                'field' => 'wx_mp_app_secret',
                'title' => '微信公众号 AppSecret',
                'value' => '',
            ],
            [
                'type' => 'input',
                'field' => 'wx_mini_app_id',
                'title' => '微信小程序 AppID',
                'value' => '',
            ],
            [
                'type' => 'password',
                'field' => 'wx_mini_app_secret',
                'title' => '微信小程序 AppSecret',
                'value' => '',
            ],
            [
                'type' => 'input',
                'field' => 'wx_mini_launch_path',
                'title' => '微信小程序支付页路径',
                'value' => '',
            ],
            [
                'type' => 'input',
                'field' => 'alipay_oauth_app_id',
                'title' => '支付宝授权应用 ID',
                'value' => '',
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_oauth_private_key',
                'title' => '支付宝授权应用私钥',
                'value' => '',
                'props' => ['rows' => 4],
            ],
            [
                'type' => 'textarea',
                'field' => 'alipay_oauth_public_key',
                'title' => '支付宝公钥',
                'value' => '',
                'props' => ['rows' => 4],
            ],
            [
                'type' => 'input',
                'field' => 'unionpay_identify',
                'title' => '云闪付 UA 标识',
                'value' => '',
            ],
            $this->directPaymentEnabledProductsField(self::PRODUCT_OPTIONS),
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '测试环境',
                'value' => false,
            ],
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

        if (array_key_exists('enabled_products', $channelConfig)) {
            $enabled = $this->enabledProducts();
            if ($enabled === [] || array_diff($enabled, array_keys(self::PRODUCT_OPTIONS)) !== []) {
                throw new PaymentException('通联已开通产品配置无效', 40200);
            }
        }
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        if ($this->isBankJsapiOrder($order)) {
            $this->ensureProduct(self::PRODUCT_BANK_JSAPI);
            return $this->bankJsapiPay($order);
        }
        if ($this->isWxMiniOrder($order)) {
            $this->ensureProduct(self::PRODUCT_WXPAY_JSAPI);
            return $this->wxJsapiPay($order, true);
        }

        return $this->executeDirectPaymentProduct($order, $this->paymentHandlers($order), '通联');
    }

    /**
     * 身份判断和 pay() 共用相同 handler、开通项及环境候选，续跑仍由平台固定原通道。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；无需补充身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        if ($this->isBankJsapiOrder($order)) {
            $this->ensureProduct(self::PRODUCT_BANK_JSAPI);
            return $this->bankIdentityRequirement($order);
        }
        if ($this->isWxMiniOrder($order)) {
            $this->ensureProduct(self::PRODUCT_WXPAY_JSAPI);
            return $this->wxIdentityRequirement($order, true);
        }

        $handlers = $this->directPaymentUsableHandlers($order, $this->paymentHandlers($order));
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));
        if (($candidates[0] ?? '') !== 'jsapi') {
            return null;
        }

        return match ($payType) {
            'alipay' => $this->alipayIdentityRequirement($order),
            'wxpay' => $this->wxIdentityRequirement($order, false),
            'bank' => $this->bankIdentityRequirement($order),
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
        $payload = $this->originalOrderReference($order, 'reqsn', 'trxid');
        try {
            $data = $this->client()->submit($this->gateway(self::QUERY_URL, self::TEST_QUERY_URL), $payload);
        } catch (AllinpaySdkException $e) {
            throw new PaymentException('通联查单失败：' . $e->getMessage(), 40200);
        }

        $this->assertResponseOrder($data, (string) ($order['pay_no'] ?? ''));
        $channelTradeNo = trim((string) ($data['trxid'] ?? ''));
        if ($channelTradeNo === '') {
            throw new PaymentException('通联查单响应缺少平台交易流水号', 40200, $this->responseSummary($data));
        }
        $amount = $this->requiredCentAmount($data, 'trxamt', '通联查单金额');
        $expectedAmount = (int) ($order['amount'] ?? 0);
        if ($expectedAmount > 0 && $amount !== $expectedAmount) {
            throw new PaymentException('通联查单金额与支付单不一致', 40200, [
                'order_amount' => $expectedAmount,
                'query_amount' => $amount,
            ]);
        }

        $status = trim((string) ($data['trxstatus'] ?? ''));
        $normalized = $this->paymentStatus($status);

        return [
            'status' => $normalized,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'chan_order_no' => (string) ($data['reqsn'] ?? $order['pay_no'] ?? ''),
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $status,
            'message' => (string) ($data['errmsg'] ?? ''),
            'paid_amount' => $amount,
            'paid_at' => $normalized === PaymentPluginStatusConstant::SUCCESS
                ? $this->parseAllinpayTime((string) ($data['fintime'] ?? ''))
                : null,
            'failed_at' => $normalized === PaymentPluginStatusConstant::FAILED
                ? $this->parseAllinpayTime((string) ($data['fintime'] ?? ''))
                : null,
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
        $payload = $this->originalOrderReference($order, 'oldreqsn', 'oldtrxid');
        try {
            $data = $this->client()->submit(
                $this->gateway(self::CLOSE_URL, self::TEST_CLOSE_URL),
                $payload,
                AllinpayClient::CASHIER_VERSION
            );
        } catch (AllinpaySdkException $e) {
            throw new PaymentUncertainException('通联关单失败：' . $e->getMessage(), 40200);
        }

        $status = trim((string) ($data['trxstatus'] ?? ''));
        if (in_array($status, ['0000', '3050'], true)) {
            return [
                'status' => PaymentPluginStatusConstant::CLOSED,
                'pay_no' => (string) ($order['pay_no'] ?? ''),
                'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
                'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'channel_status' => $status,
                'message' => (string) ($data['errmsg'] ?? '通联订单已关闭'),
            ];
        }
        if (in_array($status, self::PENDING_STATUSES, true)) {
            return [
                'status' => PaymentPluginStatusConstant::PENDING,
                'pay_no' => (string) ($order['pay_no'] ?? ''),
                'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
                'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'channel_status' => $status,
                'message' => (string) ($data['errmsg'] ?? '通联关单处理中'),
            ];
        }

        throw new PaymentDefinitiveException('通联关单失败：' . (string) ($data['errmsg'] ?? $status), 40200, $this->responseSummary($data));
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $amount = (int) ($order['refund_amount'] ?? 0);
        $refundNo = trim((string) ($order['refund_no'] ?? ''));
        if ($amount <= 0 || $refundNo === '') {
            throw new PaymentDefinitiveException('通联退款参数不完整', 40200);
        }

        $payload = [
            'trxamt' => (string) $amount,
            'reqsn' => $refundNo,
            'reason' => mb_strcut((string) ($order['refund_reason'] ?? ''), 0, 50, 'UTF-8'),
        ];
        $reference = $this->originalOrderReference($order, 'oldreqsn', 'oldtrxid');
        $payload += $reference;

        try {
            $data = $this->client()->submit($this->gateway(self::REFUND_URL, self::TEST_REFUND_URL), $payload);
        } catch (AllinpaySdkException $e) {
            throw new PaymentUncertainException('通联退款失败：' . $e->getMessage(), 40200);
        }

        $this->assertResponseOrder($data, $refundNo);
        $status = trim((string) ($data['trxstatus'] ?? ''));
        $channelRefundNo = trim((string) ($data['trxid'] ?? ''));
        if ($channelRefundNo === '') {
            throw new PaymentException('通联退款响应缺少平台退款流水号', 40200, $this->responseSummary($data));
        }
        if ($status === '0000') {
            return [
                'status' => PaymentPluginStatusConstant::SUCCESS,
                'refund_no' => $refundNo,
                'pay_no' => (string) ($order['pay_no'] ?? ''),
                'message' => '通联退款成功',
                'chan_refund_no' => $channelRefundNo,
                'refund_amount' => $amount,
                'channel_status' => $status,
            ];
        }
        if (in_array($status, self::PENDING_STATUSES, true)) {
            return [
                'status' => PaymentPluginStatusConstant::PENDING,
                'refund_no' => $refundNo,
                'pay_no' => (string) ($order['pay_no'] ?? ''),
                'message' => (string) ($data['errmsg'] ?? '通联退款处理中'),
                'chan_refund_no' => $channelRefundNo,
                'refund_amount' => $amount,
                'channel_status' => $status,
            ];
        }

        throw new PaymentDefinitiveException('通联退款失败：' . (string) ($data['errmsg'] ?? $status), 40200, $this->responseSummary($data));
    }

    /**
     * 解析并校验支付通知。
     *
     * 验签后同时校验应用、商户、订单号、人民币金额和成功状态，避免仅凭签名推进错误订单。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = $request->post();
        $signType = $payload['signtype'] ?? null;
        if (!is_scalar($signType) || strtoupper(trim((string) $signType)) !== AllinpayClient::SIGN_TYPE) {
            throw new PaymentException('通联回调签名类型不是 RSA', 40200);
        }
        try {
            $verified = $this->client()->verify($payload);
        } catch (AllinpaySdkException $e) {
            throw new PaymentException('通联回调验签失败：' . $e->getMessage(), 40200);
        }
        if (!$verified) {
            throw new PaymentException('通联回调验签失败', 40200);
        }

        foreach (['appid', 'cusid', 'cusorderid', 'trxid', 'initamt', 'trxamt', 'trxstatus'] as $field) {
            if (!isset($payload[$field]) || !is_scalar($payload[$field]) || trim((string) $payload[$field]) === '') {
                throw new PaymentException('通联回调缺少字段：' . $field, 40200);
            }
        }
        if (trim((string) $payload['appid']) !== $this->configText('app_id')) {
            throw new PaymentException('通联回调应用 ID 不匹配', 40200);
        }
        if (trim((string) $payload['cusid']) !== $this->configText('merchant_no')) {
            throw new PaymentException('通联回调商户号不匹配', 40200);
        }

        $payNo = trim((string) $payload['cusorderid']);
        if (strlen($payNo) > 32) {
            throw new PaymentException('通联回调商户订单号不合法', 40200);
        }
        $initAmount = $this->requiredCentAmount($payload, 'initamt', '通联回调原订单金额');
        $paidAmount = $this->requiredCentAmount($payload, 'trxamt', '通联回调交易金额');
        if ($initAmount !== $paidAmount) {
            throw new PaymentException('通联回调原订单金额与交易金额不一致', 40200, [
                'init_amount' => $initAmount,
                'paid_amount' => $paidAmount,
            ]);
        }
        $this->assertCnyCallback($payload);

        $channelTradeNo = trim((string) $payload['trxid']);
        if ($channelTradeNo === '') {
            throw new PaymentException('通联回调缺少平台交易流水号', 40200);
        }

        $channelStatus = trim((string) $payload['trxstatus']);
        if ($channelStatus !== '0000') {
            throw new PaymentException(
                '通联回调不是支付成功状态：' . (string) ($payload['errmsg'] ?? $channelStatus),
                40200,
                $this->responseSummary($payload)
            );
        }
        $status = PaymentPluginStatusConstant::SUCCESS;

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'message' => (string) ($payload['errmsg'] ?? $channelStatus),
            'chan_order_no' => $payNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $channelStatus,
            'channel_error_code' => $status === PaymentPluginStatusConstant::FAILED ? $channelStatus : '',
            'channel_error_msg' => $status === PaymentPluginStatusConstant::FAILED
                ? (string) ($payload['errmsg'] ?? '通联支付失败')
                : '',
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->parseAllinpayTime((string) ($payload['paytime'] ?? ''))
                : null,
            'failed_at' => $status === PaymentPluginStatusConstant::FAILED
                ? $this->parseAllinpayTime((string) ($payload['paytime'] ?? ''))
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
        return 'success';
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
     * 构建支付处理器映射。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, array<string, mixed>> 支付场景与产品处理器映射
     */
    private function paymentHandlers(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        return [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WXPAY_JSAPI,
                    'bank' => self::PRODUCT_BANK_JSAPI,
                ],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'alipay' => $this->alipayJsapiPay($order),
                        'wxpay' => $this->wxJsapiPay($order, false),
                        'bank' => $this->bankJsapiPay($order),
                    };
                },
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_CASHIER,
                    'wxpay' => self::PRODUCT_CASHIER,
                    'qqpay' => self::PRODUCT_CASHIER,
                    'bank' => self::PRODUCT_CASHIER,
                ],
                'handler' => fn (): array => $this->cashierPay($order),
            ],
            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_CASHIER,
                    'wxpay' => self::PRODUCT_CASHIER,
                    'qqpay' => self::PRODUCT_CASHIER,
                    'bank' => self::PRODUCT_CASHIER,
                ],
                'handler' => fn (): array => $this->cashierPay($order),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'qqpay' => self::PRODUCT_QQPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                ],
                'handler' => fn (): array => $this->qrcodePayByType($order, $payType),
            ],
        ];
    }

    /**
     * 按支付方式发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 平台支付方式编码
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'qqpay' => $this->qrcodePay($order, self::PRODUCT_QQPAY_SCAN, 'Q01'),
            'bank' => $this->qrcodePay($order, self::PRODUCT_BANK_SCAN, 'U01'),
            'wxpay' => $this->qrcodePay($order, self::PRODUCT_WXPAY_SCAN, 'W01'),
            'alipay' => $this->qrcodePay($order, self::PRODUCT_ALIPAY_SCAN, 'A01'),
            default => throw new PaymentException('通联不支持当前扫码支付方式', 40200),
        };
    }

    /**
     * 发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 通联产品编码
     * @param string $payType 通联交易类型
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePay(array $order, string $product, string $payType): array
    {
        $data = $this->requestUnitOrder($order, $product, $payType);
        $qrcode = trim((string) ($data['payinfo'] ?? ''));
        if ($qrcode === '') {
            throw new PaymentException('通联扫码下单未返回二维码内容', 40200, $this->responseSummary($data));
        }

        return $this->paymentResult($order, $product, 'qrcode', ['qrcode' => $qrcode], $data);
    }

    /**
     * 发起支付宝 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function alipayJsapiPay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $buyerId = trim((string) ($payment['buyer_id'] ?? ''));
        if ($buyerId === '') {
            throw new PaymentException('通联支付宝 JSAPI 缺少 buyer_id', 40200);
        }

        $data = $this->requestUnitOrder($order, self::PRODUCT_ALIPAY_JSAPI, 'A02', $buyerId);
        $tradeNo = trim((string) ($data['payinfo'] ?? ''));
        if ($tradeNo === '') {
            throw new PaymentException('通联支付宝 JSAPI 未返回交易号', 40200, $this->responseSummary($data));
        }

        return $this->paymentResult($order, self::PRODUCT_ALIPAY_JSAPI, 'jsapi', ['tradeNO' => $tradeNo], $data);
    }

    /**
     * 发起微信 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否为微信小程序支付
     * @return array<string, mixed> 标准支付结果
     */
    private function wxJsapiPay(array $order, bool $mini): array
    {
        $payment = $this->paymentPayload($order);
        $userId = $mini
            ? trim((string) ($payment['mini_openid'] ?? ''))
            : $this->firstText($payment['openid'] ?? '', $payment['sub_openid'] ?? '', $payment['wx_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException($mini ? '通联微信小程序缺少 mini_openid' : '通联微信公众号缺少 openid', 40200);
        }

        $appId = $this->wxAppId($payment, $mini);
        $payType = $mini ? 'W06' : 'W02';
        $data = $this->requestUnitOrder($order, self::PRODUCT_WXPAY_JSAPI, $payType, $userId, $appId);
        $params = json_decode((string) ($data['payinfo'] ?? ''), true);
        if (!is_array($params) || $params === []) {
            throw new PaymentException('通联微信支付未返回合法 JSAPI 参数', 40200, $this->responseSummary($data));
        }

        if ($mini) {
            return $this->paymentResult($order, self::PRODUCT_WXPAY_JSAPI, 'page', [
                '_page' => 'wechatMini',
                'request_payment' => $params,
                'app_id' => $appId,
                'description' => '通联微信小程序支付参数已生成，请由小程序调用 wx.requestPayment。',
            ], $data);
        }

        return $this->paymentResult($order, self::PRODUCT_WXPAY_JSAPI, 'jsapi', $params, $data);
    }

    /**
     * 发起云闪付 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function bankJsapiPay(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $userId = trim((string) ($payment['unionpay_user_id'] ?? ''));
        if ($userId === '') {
            $authCode = trim((string) ($payment['unionpay_auth_code'] ?? ''));
            if ($authCode === '') {
                throw new PaymentException('通联云闪付 JSAPI 缺少用户身份', 40200);
            }
            $userId = $this->unionpayUserId($authCode);
        }

        $data = $this->requestUnitOrder($order, self::PRODUCT_BANK_JSAPI, 'U02', $userId);
        $url = trim((string) ($data['payinfo'] ?? ''));
        if ($url === '') {
            throw new PaymentException('通联云闪付 JSAPI 未返回支付地址', 40200, $this->responseSummary($data));
        }

        return $this->paymentResult($order, self::PRODUCT_BANK_JSAPI, 'jump', ['url' => $url], $data);
    }

    /**
     * 使用云闪付授权码换取通联用户标识。
     *
     * identify 是通联 userAuth 协议要求的 UA 标识；换取成功后 acct 才能用于 U02 下单。
     *
     * @param string $authCode 云闪付授权码
     * @return string 通联用户标识
     */
    private function unionpayUserId(string $authCode): string
    {
        $identify = $this->configText('unionpay_identify');
        if ($identify === '') {
            throw new PaymentException('通联云闪付 JSAPI 缺少 UA identify 配置', 40200);
        }
        try {
            $data = $this->client()->submit($this->gateway(self::AUTH_CODE_URL, self::TEST_AUTH_CODE_URL), [
                'authcode' => $authCode,
                'authtype' => '02',
                'identify' => $identify,
            ]);
        } catch (AllinpaySdkException $e) {
            throw new PaymentException('通联云闪付用户身份换取失败：' . $e->getMessage(), 40200);
        }

        $userId = trim((string) ($data['acct'] ?? ''));
        if ($userId === '') {
            throw new PaymentException('通联云闪付用户身份换取未返回 acct', 40200, $this->responseSummary($data));
        }

        return $userId;
    }

    /**
     * 发起渠道收银台支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function cashierPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_CASHIER);
        try {
            $payload = $this->client()->cashierPayload([
                'trxamt' => (string) (int) $order['amount'],
                'reqsn' => (string) $order['pay_no'],
                'body' => mb_strcut((string) $order['subject'], 0, 100, 'UTF-8'),
                'validtime' => '30',
                'notify_url' => (string) $order['callback_url'],
                'returl' => (string) $order['return_url'],
                'charset' => 'UTF-8',
            ]);
        } catch (AllinpaySdkException $e) {
            throw new PaymentException('通联 H5 收银台签名失败：' . $e->getMessage(), 40200);
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jump',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => self::PRODUCT_CASHIER,
            'pay_action' => 'cashier.post',
            'pay_params' => [
                'method' => 'post',
                'action' => $this->gateway(self::CASHIER_URL, self::TEST_CASHIER_URL),
                'payload' => $payload,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 请求通联统一下单接口。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 通联产品编码
     * @param string $payType 通联交易类型
     * @param string $userId 渠道用户标识
     * @param string $subAppId 微信子应用 ID
     * @return array<string, mixed> 通联统一下单响应
     */
    private function requestUnitOrder(
        array $order,
        string $product,
        string $payType,
        string $userId = '',
        string $subAppId = ''
    ): array {
        $this->ensureProduct($product);
        $payload = [
            'trxamt' => (string) (int) $order['amount'],
            'reqsn' => (string) $order['pay_no'],
            'paytype' => $payType,
            'body' => mb_strcut((string) $order['subject'], 0, 100, 'UTF-8'),
            'validtime' => '30',
            'notify_url' => (string) $order['callback_url'],
            'cusip' => (string) $order['client_ip'],
        ];
        if ($subAppId !== '') {
            $payload['sub_appid'] = $subAppId;
        }
        if ($userId !== '') {
            $payload['acct'] = $userId;
        }
        if (in_array($payType, ['W02', 'U02'], true) && trim((string) ($order['return_url'] ?? '')) !== '') {
            $payload['front_url'] = (string) $order['return_url'];
        }

        try {
            $data = $this->client()->submit($this->gateway(self::PAY_URL, self::TEST_PAY_URL), $payload);
        } catch (AllinpaySdkException $e) {
            throw new PaymentException('通联下单失败：' . $e->getMessage(), 40200);
        }
        $this->assertResponseOrder($data, (string) $order['pay_no']);
        if ((string) ($data['trxstatus'] ?? '') !== '0000') {
            throw new PaymentException(
                '通联下单失败：' . (string) ($data['errmsg'] ?? $data['trxstatus'] ?? '渠道返回失败'),
                40200,
                $this->responseSummary($data)
            );
        }
        if (trim((string) ($data['trxid'] ?? '')) === '') {
            throw new PaymentException('通联下单成功响应缺少平台交易流水号', 40200, $this->responseSummary($data));
        }

        return $data;
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 通联产品编码
     * @param string $page 平台承接页类型
     * @param array<string, mixed> $payParams 前端调起参数
     * @param array<string, mixed> $data 通联下单响应
     * @return array<string, mixed> 标准支付结果
     */
    private function paymentResult(array $order, string $product, string $page, array $payParams, array $data): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'unitorder.pay',
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['reqsn'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trxid'] ?? ''),
        ]);
    }

    /**
     * 构建支付宝身份需求。
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
        foreach (['alipay_oauth_app_id', 'alipay_oauth_private_key', 'alipay_oauth_public_key'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException('通联支付宝 JSAPI 缺少 buyer_id，且未配置完整支付宝授权参数', 40200);
            }
        }

        return [
            'provider' => 'alipay',
            'product' => 'jsapi',
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
            'message' => '通联支付宝 JSAPI 需要先取得支付宝 user_id（buyer_id）',
        ];
    }

    /**
     * 构建微信身份需求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否为微信小程序支付
     * @return array<string, mixed>|null 微信身份要求；已有 openid 时返回 null
     */
    private function wxIdentityRequirement(array $order, bool $mini): ?array
    {
        $payment = $this->paymentPayload($order);
        $openid = $mini
            ? trim((string) ($payment['mini_openid'] ?? ''))
            : $this->firstText($payment['openid'] ?? '', $payment['sub_openid'] ?? '', $payment['wx_openid'] ?? '');
        if ($openid !== '') {
            return null;
        }

        $appId = $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        $appSecret = $this->configText($mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret');
        if ($appId === '' || $appSecret === '') {
            throw new PaymentException($mini
                ? '通联微信小程序缺少身份，且未配置小程序 AppID/AppSecret'
                : '通联微信公众号缺少身份，且未配置公众号 AppID/AppSecret', 40200);
        }

        return [
            'provider' => 'wxpay',
            'product' => $mini ? 'mini' : 'mp',
            'channel_product' => self::PRODUCT_WXPAY_JSAPI,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'openid',
            'app_id' => $appId,
            '_app_secret' => $appSecret,
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wx_mini_launch_path') : '',
            'env_version' => 'release',
            'message' => $mini
                ? '通联微信小程序支付需要先取得 mini_openid'
                : '通联微信公众号支付需要先取得 openid',
        ];
    }

    /**
     * 构建云闪付身份需求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 云闪付身份要求；已有身份时返回 null
     */
    private function bankIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['unionpay_user_id'] ?? '', $payment['unionpay_auth_code'] ?? '') !== '') {
            return null;
        }
        if ($this->configText('unionpay_identify') === '') {
            throw new PaymentException('通联云闪付 JSAPI 缺少 UA identify 配置', 40200);
        }

        return [
            'provider' => 'unionpay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_BANK_JSAPI,
            'auth_type' => 'unionpay_user_auth',
            'identity_field' => 'unionpay_auth_code',
            'app_id' => $this->configText('app_id'),
            'message' => '通联云闪付 JSAPI 需要先完成银联 userAuth 授权',
        ];
    }

    /**
     * 判断是否为云闪付 JSAPI 订单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return bool 是否为云闪付 JSAPI 订单
     */
    private function isBankJsapiOrder(array $order): bool
    {
        $payment = $this->paymentPayload($order);
        return (string) ($order['pay_type_code'] ?? '') === 'bank'
            && strtolower(trim((string) ($payment['method'] ?? ''))) === 'jsapi';
    }

    /**
     * 判断是否为微信小程序订单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return bool 是否为微信小程序订单
     */
    private function isWxMiniOrder(array $order): bool
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

    /**
     * 获取微信应用 ID。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param bool $mini 是否读取小程序应用 ID
     * @return string 微信应用 ID
     */
    private function wxAppId(array $payment, bool $mini): string
    {
        $configured = $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        $requested = trim((string) ($payment['sub_appid'] ?? ''));
        if ($configured !== '' && $requested !== '' && !hash_equals($configured, $requested)) {
            throw new PaymentException('通联微信身份 AppID 与当前通道配置不一致', 40200);
        }
        $appId = $configured !== '' ? $configured : $requested;
        if ($appId === '') {
            throw new PaymentException($mini ? '通联微信小程序缺少 AppID' : '通联微信公众号缺少 AppID', 40200);
        }

        return $appId;
    }

    /**
     * 获取支付扩展参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        $payment = (array) (($order['extra']['payment'] ?? []));
        return is_array($payment) ? $payment : [];
    }

    /**
     * 获取原交易引用。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @param string $orderKey 渠道商户订单号字段名
     * @param string $tradeKey 渠道交易流水号字段名
     * @return array<string, string> 原交易引用字段
     */
    private function originalOrderReference(array $order, string $orderKey, string $tradeKey): array
    {
        $tradeNo = $this->firstText($order['chan_trade_no'] ?? '', $order['chan_trade_no'] ?? '');
        if ($tradeNo !== '') {
            return [$tradeKey => $tradeNo];
        }
        $payNo = $this->firstText(
            $order['pay_no'] ?? '',
            $order['chan_order_no'] ?? '',
            $order['chan_order_no'] ?? '',
            $order['out_trade_no'] ?? ''
        );
        if ($payNo === '') {
            throw new PaymentException('通联原交易标识不能为空', 40200);
        }

        return [$orderKey => $payNo];
    }

    /**
     * 校验渠道响应订单。
     *
     * @param array<string, mixed> $data 通联响应数据
     * @param string $expected 预期商户订单号
     * @return void
     */
    private function assertResponseOrder(array $data, string $expected): void
    {
        $actual = trim((string) ($data['reqsn'] ?? ''));
        if ($expected !== '' && $actual === '') {
            throw new PaymentException('通联响应缺少商户订单号', 40200, [
                'expected_order_no' => $expected,
            ]);
        }
        if ($expected !== '' && !hash_equals($expected, $actual)) {
            throw new PaymentException('通联响应订单号不匹配', 40200, [
                'expected_order_no' => $expected,
                'response_order_no' => $actual,
            ]);
        }
    }

    /**
     * 读取必填整数分金额。
     *
     * @param array<string, mixed> $payload 通联报文
     * @param string $field 金额字段名
     * @param string $label 异常消息字段说明
     * @return int 金额，单位分
     */
    private function requiredCentAmount(array $payload, string $field, string $label): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new PaymentException($label . '不是合法整数分', 40200);
        }
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)$/D', $text) !== 1) {
            throw new PaymentException($label . '不是合法整数分', 40200);
        }
        $amount = (int) $text;
        if ($amount <= 0 || (string) $amount !== ltrim($text, '0')) {
            throw new PaymentException($label . '必须为正整数分', 40200);
        }

        return $amount;
    }

    /**
     * 校验通知币种为人民币。
     *
     * @param array<string, mixed> $payload 通联通知载荷
     * @return void
     */
    private function assertCnyCallback(array $payload): void
    {
        $currency = $this->firstText($payload['currency'] ?? '', $payload['trxcur'] ?? '', $payload['currencycode'] ?? '');
        if ($currency !== '' && !in_array(strtoupper($currency), ['CNY', 'RMB', '156'], true)) {
            throw new PaymentException('通联回调币种不是人民币', 40200, ['currency' => $currency]);
        }
    }

    /**
     * 将通联交易状态映射为平台支付状态。
     *
     * 2000、2008 表示渠道仍在处理，3050 表示订单已关闭，其余非空状态按失败处理。
     *
     * @param string $status 通联交易状态
     * @return string 平台支付状态
     */
    private function paymentStatus(string $status): string
    {
        if ($status === '0000') {
            return PaymentPluginStatusConstant::SUCCESS;
        }
        if ($status === '' || in_array($status, self::PENDING_STATUSES, true)) {
            return PaymentPluginStatusConstant::PENDING;
        }
        if ($status === '3050') {
            return PaymentPluginStatusConstant::CLOSED;
        }

        return PaymentPluginStatusConstant::FAILED;
    }

    /**
     * 解析通联 YmdHis 时间。
     *
     * @param string $value 通联时间文本
     * @return string|null 平台时间；空值或格式不合法时返回 null
     */
    private function parseAllinpayTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!YmdHis', $value);
        return $time instanceof DateTimeImmutable ? $time->format('Y-m-d H:i:s') : null;
    }

    /**
     * 构建渠道响应摘要。
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function responseSummary(array $data): array
    {
        $summary = [];
        foreach (['retcode', 'retmsg', 'trxstatus', 'errmsg', 'reqsn', 'trxid'] as $field) {
            if (isset($data[$field]) && is_scalar($data[$field]) && trim((string) $data[$field]) !== '') {
                $summary[$field] = mb_strcut((string) $data[$field], 0, 160, 'UTF-8');
            }
        }
        return $summary;
    }

    /**
     * 获取当前通道的通联客户端。
     *
     * @return AllinpayClient
     */
    private function client(): AllinpayClient
    {
        if ($this->client === null) {
            $this->client = new AllinpayClient([
                'merchant_no' => $this->configText('merchant_no'),
                'app_id' => $this->configText('app_id'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
            ]);
        }
        return $this->client;
    }

    /**
     * 校验通道已启用指定通联产品。
     *
     * @param string $product 通联产品编码
     * @return void
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前通联通道未开启该支付产品', 40200, ['product' => $product]);
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

    private function gateway(string $production, string $sandbox): string
    {
        return $this->configBool('sandbox') ? $sandbox : $production;
    }

    private function configBool(string $key): bool
    {
        return filter_var($this->getConfig($key, false), FILTER_VALIDATE_BOOL);
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
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
}
