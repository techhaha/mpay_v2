<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\interface\RefundQueryInterface;
use app\common\sdk\suixingpay\SuixingpayClient;
use app\common\sdk\suixingpay\SuixingpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use support\Request;
use support\Response;
use Throwable;

/**
 * 随行付 OpenAPI 支付插件。
 *
 * 负责扫码、JSAPI、托管小程序支付以及查单、关单、退款和支付通知适配。
 * 通道协议与天阙 OpenAPI 同源，但插件标识、配置、SDK 和运行数据保持隔离。
 */
class SuixingpayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface,
    RefundQueryInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PROFILE_CURRENT = 'current';
    private const PROFILE_RAINBOW_LEGACY = 'rainbow_legacy';

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_WXPAY_APPLET_PLUGIN = 'wxpay_applet_plugin';
    private const PRODUCT_WXPAY_APPLET_CASHIER = 'wxpay_applet_cashier';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    /**
     * 兼容 MPAY V2 早期 suixingpay_api 已保存的无前缀配置；新 schema 始终写入独立键。
     *
     * @var array<string, string>
     */
    private const LEGACY_CONFIG_ALIASES = [
        'suixingpay_api_profile' => 'api_profile',
        'suixingpay_org_id' => 'org_id',
        'suixingpay_merchant_no' => 'merchant_no',
        'suixingpay_platform_public_key' => 'platform_public_key',
        'suixingpay_merchant_private_key' => 'merchant_private_key',
        'suixingpay_wechat_mp_app_id' => 'wechat_mp_app_id',
        'suixingpay_wechat_mp_app_secret' => 'wechat_mp_app_secret',
        'suixingpay_wechat_mini_app_id' => 'wechat_mini_app_id',
        'suixingpay_wechat_mini_app_secret' => 'wechat_mini_app_secret',
        'suixingpay_wechat_mini_launch_path' => 'wechat_mini_launch_path',
        'suixingpay_wechat_mini_env_version' => 'wechat_mini_env_version',
        'suixingpay_alipay_app_id' => 'alipay_app_id',
        'suixingpay_alipay_app_private_key' => 'alipay_app_private_key',
        'suixingpay_alipay_public_key' => 'alipay_public_key',
        'suixingpay_alipay_mini_launch_path' => 'alipay_mini_launch_path',
        'suixingpay_sandbox' => 'sandbox',
        'suixingpay_api_base_url' => 'api_base_url',
    ];

    private ?SuixingpayClient $client = null;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'suixingpay_api',
        'name' => '随行付OpenAPI支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '2.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造随行付支付插件。
     *
     * @param PayOrderRepository $payOrderRepository 支付通知归属及产品快照校验所用仓储
     */
    public function __construct(private readonly PayOrderRepository $payOrderRepository)
    {
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
                'field' => 'suixingpay_api_profile',
                'title' => '接口产品版本',
                'value' => self::PROFILE_CURRENT,
                'options' => [
                    ['label' => '机构当前天阙 OpenAPI 文档版', 'value' => self::PROFILE_CURRENT],
                    ['label' => '彩虹 suixingpay 旧接口版', 'value' => self::PROFILE_RAINBOW_LEGACY],
                ],
                'validate' => [['required' => true, 'message' => '接口产品版本不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'suixingpay_org_id',
                'title' => '机构编号',
                'value' => '',
                'validate' => [['required' => true, 'message' => '机构编号不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'suixingpay_merchant_no',
                'title' => '商户编号',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户编号不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'suixingpay_platform_public_key',
                'title' => '随行付平台公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [['required' => true, 'message' => '平台公钥不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'suixingpay_merchant_private_key',
                'title' => '随行付商户私钥（PKCS#8/PKCS#1）',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [['required' => true, 'message' => '商户私钥不能为空']],
            ],
            $this->productField(),
            ['type' => 'input', 'field' => 'suixingpay_wechat_mp_app_id', 'title' => '微信公众号 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'suixingpay_wechat_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'suixingpay_wechat_mini_app_id', 'title' => '微信小程序 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'suixingpay_wechat_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'suixingpay_wechat_mini_launch_path', 'title' => '微信小程序承接路径', 'value' => ''],
            ['type' => 'input', 'field' => 'suixingpay_applet_plugin_app_id', 'title' => '随行付小程序支付插件 AppID', 'value' => ''],
            [
                'type' => 'radio',
                'field' => 'suixingpay_wechat_mini_env_version',
                'title' => '微信小程序版本',
                'value' => 'release',
                'options' => [
                    ['label' => '正式版', 'value' => 'release'],
                    ['label' => '体验版', 'value' => 'trial'],
                    ['label' => '开发版', 'value' => 'develop'],
                ],
            ],
            ['type' => 'input', 'field' => 'suixingpay_alipay_app_id', 'title' => '支付宝应用 AppID', 'value' => ''],
            ['type' => 'textarea', 'field' => 'suixingpay_alipay_app_private_key', 'title' => '支付宝应用私钥', 'value' => '', 'props' => ['rows' => 4]],
            ['type' => 'textarea', 'field' => 'suixingpay_alipay_public_key', 'title' => '支付宝公钥', 'value' => '', 'props' => ['rows' => 4]],
            ['type' => 'input', 'field' => 'suixingpay_alipay_mini_launch_path', 'title' => '支付宝小程序承接路径', 'value' => ''],
            [
                'type' => 'switch',
                'field' => 'suixingpay_sandbox',
                'title' => '测试环境',
                'value' => false,
                'props' => ['checkedText' => '测试', 'uncheckedText' => '生产'],
            ],
            [
                'type' => 'input',
                'field' => 'suixingpay_api_base_url',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => ['placeholder' => '留空使用随行付默认网关'],
            ],
        ];
    }

    /**
     * 初始化插件。
     *
     * 初始化时清除旧客户端，并在请求渠道前校验接口版本、密钥和已开通产品。
     *
     * @param array<string, mixed> $channelConfig 当前支付通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;

        foreach (['suixingpay_org_id' => '机构编号', 'suixingpay_merchant_no' => '商户编号', 'suixingpay_platform_public_key' => '平台公钥', 'suixingpay_merchant_private_key' => '商户私钥'] as $field => $label) {
            if ($this->configText($field) === '') {
                throw new PaymentException('随行付' . $label . '不能为空', 40200);
            }
        }
        if (!in_array($this->apiProfile(), [self::PROFILE_CURRENT, self::PROFILE_RAINBOW_LEGACY], true)) {
            throw new PaymentException('随行付接口产品版本配置无效', 40200);
        }
        $supported = [
            self::PRODUCT_ALIPAY_SCAN,
            self::PRODUCT_ALIPAY_JSAPI,
            self::PRODUCT_WXPAY_SCAN,
            self::PRODUCT_WXPAY_MP,
            self::PRODUCT_WXPAY_MINI,
            self::PRODUCT_WXPAY_APPLET_PLUGIN,
            self::PRODUCT_WXPAY_APPLET_CASHIER,
            self::PRODUCT_BANK_SCAN,
        ];
        $products = $this->enabledProducts();
        if ($products === [] || array_diff($products, $supported) !== []) {
            throw new PaymentException('随行付已开通产品配置无效', 40200);
        }
    }

    /**
     * 声明当前订单的支付身份需求。
     *
     * 仅在最终选中 JSAPI 时声明对应应用作用域的支付宝或微信用户身份。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed>|null 缺少身份时的授权要求，已有身份或无需授权时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $order = $this->selectionOrder($order);
        $payment = $this->paymentPayload($order);
        $selectedHandler = $this->selectedHandler($order);
        if ($selectedHandler === 'mini' && trim((string) ($payment['applet_source'] ?? '')) !== '') {
            return null;
        }
        if (!in_array($selectedHandler, ['jsapi', 'mini'], true)) {
            return null;
        }

        $payType = trim((string) ($order['pay_type_code'] ?? ''));
        if ($payType === 'alipay') {
            if ($this->firstText($payment['buyer_id'] ?? '') !== '') {
                return null;
            }

            $appId = $this->requiredConfig('suixingpay_alipay_app_id', '支付宝应用 AppID');
            $privateKey = $this->requiredConfig('suixingpay_alipay_app_private_key', '支付宝应用私钥');
            $publicKey = $this->requiredConfig('suixingpay_alipay_public_key', '支付宝公钥');

            return [
                'provider' => 'alipay',
                'product' => self::PRODUCT_ALIPAY_JSAPI,
                'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
                'auth_type' => $this->isAlipayMiniIntent($payment) ? 'alipay_mini' : 'alipay_oauth',
                'identity_field' => 'buyer_id',
                'app_id' => $appId,
                'mini_path' => $this->configText('suixingpay_alipay_mini_launch_path'),
                'scope' => 'auth_base',
                '_alipay_config' => [
                    'mode' => 'key',
                    'app_id' => $appId,
                    'private_key' => $privateKey,
                    'alipay_public_key' => $publicKey,
                    'sandbox' => $this->configBool('suixingpay_sandbox'),
                ],
                'message' => '随行付支付宝 JSAPI 仅接受当前应用作用域的 buyer_id（支付宝 userId）',
            ];
        }

        if ($payType !== 'wxpay') {
            return null;
        }

        $mini = $this->isMiniIntent($payment);
        $identity = $mini
            ? $this->firstText($payment['mini_openid'] ?? '')
            : $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
        if ($identity !== '') {
            return null;
        }
        $appId = $this->requiredConfig(
            $mini ? 'suixingpay_wechat_mini_app_id' : 'suixingpay_wechat_mp_app_id',
            $mini ? '微信小程序 AppID' : '微信公众号 AppID'
        );
        $appSecret = $this->requiredConfig(
            $mini ? 'suixingpay_wechat_mini_app_secret' : 'suixingpay_wechat_mp_app_secret',
            $mini ? '微信小程序 AppSecret' : '微信公众号 AppSecret'
        );

        return [
            'provider' => 'wxpay',
            'product' => $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP,
            'channel_product' => $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'sub_openid',
            'identity_aliases' => $mini ? [] : ['openid'],
            'app_id' => $appId,
            'scope' => 'snsapi_base',
            '_app_secret' => $appSecret,
            'mini_path' => $mini ? $this->configText('suixingpay_wechat_mini_launch_path') : '',
            'env_version' => $mini ? $this->configText('suixingpay_wechat_mini_env_version', 'release') : '',
            'mini_launch_type' => $mini
                ? (strtolower(trim((string) ($order['_env'] ?? ''))) === 'wechat' ? 'url_link' : 'url_scheme')
                : '',
            'message' => $mini
                ? '随行付微信小程序支付需要当前小程序作用域的 mini_openid'
                : '随行付微信公众号支付需要当前公众号作用域的 sub_openid/openid',
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
        $order = $this->selectionOrder($order);
        return $this->executeDirectPaymentProduct($order, $this->paymentHandlers($order), '随行付');
    }

    /**
     * 查询支付订单。
     *
     * 查单响应须匹配本地订单号、商户号、币种、已知金额及已保存的渠道交易号。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '随行付查单缺少支付单号');
        try {
            $data = $this->client()->submit(SuixingpayClient::PATH_TRADE_QUERY, [
                'mno' => $this->configText('suixingpay_merchant_no'),
                'ordNo' => $payNo,
            ]);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付查单失败：' . $e->getMessage(), 40200, [
                'pay_no' => $payNo,
                'request_id' => $this->client()->lastRequestId(),
            ]);
        }

        $this->assertBusinessSuccess($data, '查单');
        $this->assertResponseOrder($data, $payNo, '查单');
        $this->assertResponseMerchant($data, '查单');
        $status = $this->tradeStatus((string) ($data['tranSts'] ?? ''));
        $expectedAmount = (int) ($order['amount'] ?? -1);
        if ($status === PaymentPluginStatusConstant::SUCCESS && !array_key_exists('oriTranAmt', $data)) {
            throw new PaymentException('随行付成功查单响应缺少原交易金额', 40200, ['pay_no' => $payNo]);
        }
        if (array_key_exists('oriTranAmt', $data) && $expectedAmount >= 0) {
            $this->assertAmountMatches($data['oriTranAmt'], $expectedAmount, '随行付查单');
        }
        $this->assertCurrency($data, '随行付查单');
        $tradeNumbers = $this->tradeNumbers($data);
        $storedTradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($storedTradeNo !== '' && $tradeNumbers !== [] && !in_array($storedTradeNo, $tradeNumbers, true)) {
            throw new PaymentException('随行付查单渠道交易号与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }
        $channelTradeNo = $storedTradeNo !== '' ? $storedTradeNo : (string) ($tradeNumbers[0] ?? '');
        if ($status === PaymentPluginStatusConstant::SUCCESS && $channelTradeNo === '') {
            throw new PaymentException('随行付成功查单缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $expectedAmount : null,
            'chan_order_no' => (string) ($data['ordNo'] ?? $payNo),
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => strtoupper((string) ($data['tranSts'] ?? '')),
            'message' => (string) ($data['bizMsg'] ?? $data['tranSts'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->gatewayTime($data['payTime'] ?? '') : null,
            'request_id' => $this->client()->lastRequestId(),
        ];
    }

    /**
     * 关闭支付订单。
     *
     * 直接关单未得到可确认结果时会主动查单，仅在查得关闭终态后返回成功。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     *
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '随行付关单缺少支付单号');
        $path = $this->apiProfile() === self::PROFILE_CURRENT
            ? SuixingpayClient::PATH_CLOSE_CURRENT
            : SuixingpayClient::PATH_CANCEL_LEGACY;
        try {
            $data = $this->client()->submit($path, [
                'mno' => $this->configText('suixingpay_merchant_no'),
                'origOrderNo' => $payNo,
            ]);
            $this->assertBusinessSuccess($data, '关单');
            $this->assertResponseOrder($data, $payNo, '关单', ['origOrderNo', 'ordNo']);
            $this->assertResponseMerchant($data, '关单');
            $channelStatus = strtoupper((string) ($data['tranSts'] ?? ''));

            $expectedStatus = $this->apiProfile() === self::PROFILE_CURRENT ? 'CLOSED' : 'CANCELED';
            if (!hash_equals($expectedStatus, $channelStatus)) {
                throw new PaymentException('随行付关单返回状态异常', 40200, [
                    'channel_status' => $channelStatus,
                    'expected_status' => $expectedStatus,
                ]);
            }

            return [
                'status' => PaymentPluginStatusConstant::CLOSED,
                'pay_no' => $payNo,
                'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
                'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'message' => (string) ($data['bizMsg'] ?? '关单成功'),
                'channel_status' => $channelStatus,
                'request_id' => $this->client()->lastRequestId(),
            ];
        } catch (SuixingpaySdkException|PaymentException $e) {
            $query = $this->safeQueryForReconciliation($order);
            if (($query['status'] ?? '') === PaymentPluginStatusConstant::CLOSED) {
                return [
                    'status' => PaymentPluginStatusConstant::CLOSED,
                    'pay_no' => $payNo,
                    'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
                    'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                    'message' => '订单已关闭',
                    'channel_status' => (string) ($query['channel_status'] ?? ''),
                    'request_id' => (string) ($query['request_id'] ?? ''),
                ];
            }
            throw new PaymentException('随行付关单失败：' . $e->getMessage(), 40200, ['pay_no' => $payNo]);
        }
    }

    /**
     * 发起退款。
     *
     * 请求异常或非成功业务码会优先退款查单；仍无法确认时按结果不确定处理。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     *
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = (string) $order['pay_no'];
        $refundNo = (string) $order['refund_no'];
        $refundAmount = (int) $order['refund_amount'];
        $payload = [
            'mno' => $this->configText('suixingpay_merchant_no'),
            'ordNo' => $refundNo,
            'origOrderNo' => $payNo,
            'amt' => FormatHelper::amount($refundAmount),
        ];
        $refundReason = trim((string) ($order['refund_reason'] ?? ''));
        if ($refundReason !== '') {
            $payload['refundReason'] = mb_strcut($refundReason, 0, 80, 'UTF-8');
        }

        $requestError = '';
        try {
            $data = $this->client()->submit(SuixingpayClient::PATH_REFUND, $payload);
        } catch (SuixingpaySdkException $e) {
            $requestError = $e->getMessage();
            $data = [];
        }

        if (($data['bizCode'] ?? '') !== '0000' || !in_array($data['tranSts'] ?? '', ['REFUNDSUC', 'REFUNDFAIL'], true)) {
            // 原请求可能已受理，只查同一退款单一次；查询响应不交给申请响应的字段规则。
            try {
                $result = $this->queryRefund($order);
            } catch (PaymentException $e) {
                if ($requestError === '') {
                    throw $e;
                }
                throw new PaymentUncertainException('随行付退款请求未确认：' . $requestError . '；查询未确认：' . $e->getMessage(), 40200,
                    array_replace($e->getData(), ['refund_no' => $refundNo]));
            }
        } else {
            $result = $this->parseRefundResult($order, $data, false);
        }

        if ($result['status'] === PaymentPluginStatusConstant::FAILED) {
            throw new PaymentDefinitiveException($result['message'], 40200, ['refund_no' => $refundNo]);
        }

        return $result;
    }

    /**
     * 查询随行付退款状态并核对本次退款的金额与标识。
     *
     * 本方法只读上游，不重发退款；failed 是可信查询终态，unknown 不推断失败。
     */
    public function queryRefund(array $refund): array
    {
        $refundNo = (string) $refund['refund_no'];

        try {
            $data = $this->client()->submit(SuixingpayClient::PATH_REFUND_QUERY, [
                'mno' => $this->configText('suixingpay_merchant_no'),
                'ordNo' => $refundNo,
            ]);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentUncertainException('随行付退款查询失败：' . $e->getMessage(), 40200, [
                'refund_no' => $refundNo,
            ]);
        }

        return $this->parseRefundResult($refund, $data, true);
    }

    /**
     * 解析并校验支付通知。
     *
     * 除验签外，还按本地支付单核对通道、机构/商户、金额、币种、产品快照和渠道交易号。
     *
     * @param Request $request 支付通知请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = json_decode($request->rawBody(), true);
        if (!is_array($payload) || $payload === []) {
            throw new PaymentException('随行付回调不是合法 JSON', 40200);
        }
        try {
            $verified = $this->client()->verify($payload);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付回调验签异常：' . $e->getMessage(), 40200);
        }
        if (!$verified) {
            throw new PaymentException('随行付回调验签失败', 40200);
        }
        if ((string) ($payload['bizCode'] ?? '') !== '0000') {
            throw new PaymentException('随行付回调不是支付成功通知', 40200, ['biz_code' => (string) ($payload['bizCode'] ?? '')]);
        }

        $payNo = $this->requiredText($payload['ordNo'] ?? '', '随行付回调缺少订单号');
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('随行付回调支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('随行付回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }
        $mno = trim((string) ($payload['mno'] ?? ''));
        if ($mno === '' || !hash_equals($this->configText('suixingpay_merchant_no'), $mno)) {
            throw new PaymentException('随行付回调商户编号不匹配', 40200, ['pay_no' => $payNo]);
        }
        $orgId = trim((string) ($payload['orgId'] ?? ''));
        if ($orgId !== '' && !hash_equals($this->configText('suixingpay_org_id'), $orgId)) {
            throw new PaymentException('随行付回调机构编号不匹配', 40200, ['pay_no' => $payNo]);
        }
        $this->assertAmountMatches($payload['amt'] ?? '', (int) $payOrder->pay_amount, '随行付回调');
        $this->assertCurrency($payload, '随行付回调');
        $this->assertNotifyProduct($payload, $payOrder);

        $tradeNumbers = $this->tradeNumbers($payload);
        if ($tradeNumbers === []) {
            throw new PaymentException('随行付成功回调缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !in_array($storedTradeNo, $tradeNumbers, true)) {
            throw new PaymentException('随行付回调渠道交易号与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }
        $tradeNo = $storedTradeNo !== '' ? $storedTradeNo : $tradeNumbers[0];

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'message' => (string) ($payload['bizMsg'] ?? '支付成功'),
            'pay_no' => $payNo,
            'paid_amount' => (int) $payOrder->pay_amount,
            'chan_order_no' => $payNo,
            'chan_trade_no' => $tradeNo,
            'channel_status' => '0000',
            'paid_at' => $this->gatewayTime($payload['payTime'] ?? ''),
        ];
    }

    /**
     * 返回渠道要求的支付通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function notifySuccess(): string|Response
    {
        return '{"code":"success","msg":"成功"}';
    }

    /**
     * 返回渠道要求的支付通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function notifyFail(): string|Response
    {
        return '{"code":"fail","msg":"失败"}';
    }

    /**
     * 构建支付处理器映射。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 按承接方式组织的产品与处理器定义
     */
    private function paymentHandlers(array $order): array
    {
        $payType = trim((string) ($order['pay_type_code'] ?? ''));
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        $appletSource = trim((string) ($payment['applet_source'] ?? ''));
        $wxJsapiProduct = $this->isMiniIntent($payment) ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP;
        $appletProduct = $this->appletProduct($payment);
        $jsapiHandler = ($payType === 'wxpay' && $wxJsapiProduct === self::PRODUCT_WXPAY_MINI)
            || ($payType === 'alipay' && $method === 'mini')
            ? 'mini'
            : 'jsapi';

        if ($payType === 'wxpay' && $method === 'mini' && in_array($appletSource, ['00', '01'], true)) {
            return [
                'mini' => [
                    'products' => ['wxpay' => $appletProduct],
                    'handler' => fn (): array => $this->appletPay($order, $appletProduct),
                ],
                'qrcode' => [
                    'products' => ['wxpay' => self::PRODUCT_WXPAY_SCAN],
                    'handler' => fn (): array => $this->scanPayByType($order, $payType),
                ],
            ];
        }

        return [
            $jsapiHandler => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => $wxJsapiProduct,
                ],
                'handler' => function () use ($order, $payType, $wxJsapiProduct): array {
                    return match ($payType) {
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY'),
                        'wxpay' => $this->jsapiPay($order, $wxJsapiProduct, 'WECHAT'),
                        default => throw new PaymentException('随行付 JSAPI 不支持当前支付方式', 40200),
                    };
                },
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],
                'handler' => fn (): array => $this->scanPayByType($order, $payType),
            ],
        ];
    }

    /**
     * 选择当前支付处理器。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function selectedHandler(array $order): string
    {
        $handlers = $this->directPaymentUsableHandlers($order, $this->paymentHandlers($order));
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));

        return (string) ($candidates[0] ?? '');
    }

    /**
     * 按支付方式发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function scanPayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->scanPay($order, self::PRODUCT_BANK_SCAN, 'UNIONPAY'),
            'wxpay' => $this->scanPay($order, self::PRODUCT_WXPAY_SCAN, 'WECHAT'),
            'alipay' => $this->scanPay($order, self::PRODUCT_ALIPAY_SCAN, 'ALIPAY'),
            default => throw new PaymentException('随行付扫码不支持当前支付方式', 40200),
        };
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品代码
     * @param string $payType 随行付支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function scanPay(array $order, string $product, string $payType): array
    {
        $amount = $this->positiveCents($order['amount'] ?? 0, '随行付支付金额');
        $path = $this->apiProfile() === self::PROFILE_CURRENT
            ? SuixingpayClient::PATH_ACTIVE_SCAN_CURRENT
            : SuixingpayClient::PATH_ACTIVE_SCAN_LEGACY;
        try {
            $data = $this->client()->submit($path, [
                'mno' => $this->configText('suixingpay_merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount($amount),
                'payType' => $payType,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付扫码下单失败：' . $e->getMessage(), 40200);
        }

        $this->assertBusinessSuccess($data, '扫码下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '扫码下单');
        $this->assertResponseMerchant($data, '扫码下单');
        $url = trim((string) ($data['payUrl'] ?? ''));
        if ($url === '') {
            throw new PaymentException('随行付扫码下单未返回支付地址', 40200, $this->diagnosticContext($data));
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => ltrim($path, '/'),
            'pay_params' => ['qrcode' => $url],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['uuid'] ?? '', $data['sxfUuid'] ?? '', $data['transactionId'] ?? ''),
        ]);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * 根据支付宝、公众号或小程序产品读取严格对应的用户与应用标识。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品代码
     * @param string $payType 随行付支付方式代码
     *
     * @return array<string, mixed> 标准 JSAPI 待支付结果
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $amount = $this->positiveCents($order['amount'] ?? 0, '随行付支付金额');
        $payment = $this->paymentPayload($order);
        $mini = $product === self::PRODUCT_WXPAY_MINI;
        if ($payType === 'ALIPAY') {
            $userId = $this->firstText($payment['buyer_id'] ?? '');
            $subAppId = '';
            $payWay = '02';
            if ($userId === '') {
                throw new PaymentException('随行付支付宝 JSAPI 缺少 buyer_id（不接受 buyer_open_id）', 40200);
            }
        } elseif ($mini) {
            $userId = $this->firstText($payment['mini_openid'] ?? '');
            $subAppId = $this->wechatSubAppId($payment, true);
            $payWay = '03';
            if ($userId === '') {
                throw new PaymentException('随行付微信小程序支付缺少 mini_openid', 40200);
            }
        } else {
            $userId = $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
            $subAppId = $this->wechatSubAppId($payment, false);
            $payWay = '02';
            if ($userId === '') {
                throw new PaymentException('随行付微信公众号支付缺少 sub_openid/openid', 40200);
            }
        }

        try {
            $data = $this->client()->submit(SuixingpayClient::PATH_JSAPI_SCAN, [
                'mno' => $this->configText('suixingpay_merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount($amount),
                'payType' => $payType,
                'payWay' => $payWay,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'subAppid' => $subAppId,
                'userId' => $userId,
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付 JSAPI 下单失败：' . $e->getMessage(), 40200);
        }

        $this->assertBusinessSuccess($data, 'JSAPI下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], 'JSAPI下单');
        $this->assertResponseMerchant($data, 'JSAPI下单');
        if ($payType === 'WECHAT') {
            $params = [
                'appId' => (string) ($data['payAppId'] ?? ''),
                'timeStamp' => (string) ($data['payTimeStamp'] ?? ''),
                'nonceStr' => (string) ($data['paynonceStr'] ?? ''),
                'package' => (string) ($data['payPackage'] ?? ''),
                'signType' => (string) ($data['paySignType'] ?? ''),
                'paySign' => (string) ($data['paySign'] ?? ''),
            ];
            foreach ($params as $field => $value) {
                if ($value === '') {
                    throw new PaymentException('随行付微信 JSAPI 响应缺少 ' . $field, 40200, $this->diagnosticContext($data));
                }
            }
        } else {
            $tradeNo = trim((string) ($data['source'] ?? ''));
            if ($tradeNo === '') {
                throw new PaymentException('随行付支付宝 JSAPI 响应缺少 source', 40200, $this->diagnosticContext($data));
            }
            $params = ['tradeNO' => $tradeNo];
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => ltrim(SuixingpayClient::PATH_JSAPI_SCAN, '/'),
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['uuid'] ?? '', $data['sxfUuid'] ?? '', $data['transactionId'] ?? ''),
        ]);
    }

    /**
     * 发起随行付托管小程序支付。
     *
     * 该处理器只接受 `method=mini + applet_source`，返回的 appId/path 或插件 key 必须由微信小程序容器消费。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 托管小程序插件或半屏收银台产品代码
     *
     * @return array<string, mixed> 标准小程序页面待支付结果
     */
    private function appletPay(array $order, string $product): array
    {
        $amountCents = $this->positiveCents($order['amount'] ?? 0, '随行付支付金额');
        $source = $product === self::PRODUCT_WXPAY_APPLET_CASHIER ? '01' : '00';
        try {
            $data = $this->client()->submit(SuixingpayClient::PATH_APPLET_SCAN_PRE, [
                'mno' => $this->configText('suixingpay_merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount($amountCents),
                'appletSource' => $source,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付小程序收银台下单失败：' . $e->getMessage(), 40200);
        }

        $this->assertBusinessSuccess($data, '小程序收银台下单');
        $this->assertResponseOrder($data, (string) $order['pay_no'], '小程序收银台下单');
        $this->assertResponseMerchant($data, '小程序收银台下单');
        if ($source === '00') {
            $appId = $this->configText('suixingpay_applet_plugin_app_id');
            $key = trim((string) ($data['key'] ?? ''));
            $amount = trim((string) ($data['amt'] ?? ''));
            if ($appId === '' || $key === '' || $amount === '') {
                throw new PaymentException('随行付小程序支付插件响应或插件 AppID 配置不完整', 40200, $this->diagnosticContext($data));
            }
            $this->assertAmountMatches($amount, $amountCents, '随行付小程序支付插件');
            $params = [
                'applet_source' => '00',
                'app_id' => $appId,
                'key' => $key,
                'amt' => $amount,
            ];
        } else {
            $appId = trim((string) ($data['appId'] ?? ''));
            $path = trim((string) ($data['path'] ?? ''));
            if ($appId === '' || $path === '') {
                throw new PaymentException('随行付半屏小程序收银台响应缺少 appId/path', 40200, $this->diagnosticContext($data));
            }
            $params = [
                'applet_source' => '01',
                'app_id' => $appId,
                'path' => $path,
            ];
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'page',
            'pay_type' => 'wxpay',
            'pay_product' => $product,
            'pay_action' => ltrim(SuixingpayClient::PATH_APPLET_SCAN_PRE, '/'),
            'pay_params' => [
                '_page' => 'page',
                'execution_context' => 'wechat_mini_program',
                'launch_type' => $source === '00'
                    ? 'wechat_mini_program_plugin'
                    : 'wechat_mini_program_half_screen',
                'params' => $params,
                'description' => $source === '00'
                    ? '请由微信小程序容器使用随行付支付插件 AppID、key 和 amt 发起支付。'
                    : '请由微信小程序容器使用随行付返回的 appId 和 path 打开半屏收银台。',
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['uuid'] ?? '', $data['sxfUuid'] ?? ''),
        ]);
    }

    /**
     * 获取微信子应用 ID。
     *
     * 若订单携带 AppID，必须与当前通道配置一致，避免复用其他应用作用域的用户身份。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param bool $mini 是否读取微信小程序 AppID
     */
    private function wechatSubAppId(array $payment, bool $mini): string
    {
        $configured = $mini ? $this->configText('suixingpay_wechat_mini_app_id') : $this->configText('suixingpay_wechat_mp_app_id');
        $provided = $mini
            ? $this->firstText($payment['mini_app_id'] ?? '', $payment['sub_appid'] ?? '')
            : $this->firstText($payment['sub_appid'] ?? '');
        if ($configured === '') {
            throw new PaymentException($mini ? '随行付微信小程序 AppID 未配置' : '随行付微信公众号 AppID 未配置', 40200);
        }
        if ($provided !== '' && !hash_equals($configured, $provided)) {
            throw new PaymentException('随行付微信 subAppid 与当前通道应用配置不一致', 40200);
        }

        return $configured;
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

        return $this->firstText($payment['mini_openid'] ?? '', $payment['mini_app_id'] ?? '') !== ''
            || in_array($payment['is_mini'] ?? false, [true, 1, '1', 'true', 'on'], true)
            || strtolower(trim((string) ($payment['wechat_scope'] ?? ''))) === 'mini';
    }

    /**
     * 判断是否请求支付宝小程序支付。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     */
    private function isAlipayMiniIntent(array $payment): bool
    {
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            return $method === 'mini';
        }

        return in_array($payment['is_mini'] ?? false, [true, 1, '1', 'true', 'on'], true)
            || strtolower(trim((string) ($payment['alipay_scope'] ?? ''))) === 'mini';
    }

    /**
     * 解析小程序支付产品。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     */
    private function appletProduct(array $payment): string
    {
        return trim((string) ($payment['applet_source'] ?? '')) === '01'
            ? self::PRODUCT_WXPAY_APPLET_CASHIER
            : self::PRODUCT_WXPAY_APPLET_PLUGIN;
    }

    /**
     * 将协议层支付意图归一化为产品选择器可识别的订单上下文。
     *
     * `method=mini + applet_source` 选择托管小程序；普通 mini 意图选择直接小程序 JSAPI。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 已归一化支付意图的订单参数
     */
    private function selectionOrder(array $order): array
    {
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        $hostedApplet = $method === 'mini'
            && in_array(trim((string) ($payment['applet_source'] ?? '')), ['00', '01'], true);
        if ((string) ($order['pay_type_code'] ?? '') === 'wxpay'
            && !$hostedApplet
            && $this->isMiniIntent($payment)
            && $method === ''
        ) {
            $payment['method'] = 'mini';
        }
        $extra = (array) ($order['extra'] ?? []);
        $extra['payment'] = $payment;
        $order['extra'] = $extra;

        return $order;
    }

    /**
     * 校验通知支付产品。
     *
     * @param array<string, mixed> $payload 已验签的通知参数
     * @param PayOrder $payOrder 通知对应的本地支付单
     */
    private function assertNotifyProduct(array $payload, PayOrder $payOrder): void
    {
        $ext = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($ext['payment_context'] ?? []);
        $product = trim((string) ($context['pay_product'] ?? ''));
        $expected = match ($product) {
            self::PRODUCT_ALIPAY_SCAN => ['ALIPAY', '00'],
            self::PRODUCT_ALIPAY_JSAPI => ['ALIPAY', '02'],
            self::PRODUCT_WXPAY_SCAN => ['WECHAT', '00'],
            self::PRODUCT_WXPAY_MP => ['WECHAT', '02'],
            self::PRODUCT_WXPAY_MINI => ['WECHAT', '03'],
            self::PRODUCT_WXPAY_APPLET_PLUGIN, self::PRODUCT_WXPAY_APPLET_CASHIER => ['WECHAT', '03'],
            self::PRODUCT_BANK_SCAN => ['UNIONPAY', '00'],
            default => throw new PaymentException('随行付回调无法识别支付单产品快照', 40200, ['pay_no' => (string) $payOrder->pay_no]),
        };
        $payType = strtoupper(trim((string) ($payload['payType'] ?? '')));
        if ($payType === '' || !hash_equals($expected[0], $payType)) {
            throw new PaymentException('随行付回调支付类型与支付单产品不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
        if ($expected[1] !== null) {
            $payWay = trim((string) ($payload['payWay'] ?? ''));
            if (!hash_equals($expected[1], $payWay)) {
                throw new PaymentException('随行付回调 payWay 与公众号/小程序产品不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
            }
        }
    }

    /**
     * 校验渠道业务是否成功。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $scene 用于异常提示的业务场景
     */
    private function assertBusinessSuccess(array $data, string $scene): void
    {
        $code = trim((string) ($data['bizCode'] ?? ''));
        if ($code !== '0000') {
            $message = $code === '' ? '响应缺少 bizCode' : (string) ($data['bizMsg'] ?? $code);
            throw new PaymentException('随行付' . $scene . '失败：' . $message, 40200, $this->diagnosticContext($data));
        }
    }

    /**
     * 校验渠道响应订单。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $expected 预期订单号
     * @param string $scene 用于异常提示的业务场景
     * @param array<int, string> $fields 可承载订单号的响应字段
     */
    private function assertResponseOrder(array $data, string $expected, string $scene, array $fields = ['ordNo']): void
    {
        $found = false;
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $actual = trim((string) $data[$field]);
            if ($actual === '') {
                continue;
            }
            $found = true;
            if (!hash_equals($expected, $actual)) {
                throw new PaymentException('随行付' . $scene . '返回订单号不匹配', 40200, ['expected_order_no' => $expected]);
            }
        }
        if (!$found) {
            throw new PaymentException('随行付' . $scene . '响应缺少订单号', 40200, ['expected_order_no' => $expected]);
        }
    }

    /**
     * 校验渠道响应商户。
     *
     * @param array<string, mixed> $data 渠道响应
     * @param string $scene 用于异常提示的业务场景
     */
    private function assertResponseMerchant(array $data, string $scene): void
    {
        if (!array_key_exists('mno', $data)) {
            return;
        }
        $mno = trim((string) $data['mno']);
        if ($mno !== '' && !hash_equals($this->configText('suixingpay_merchant_no'), $mno)) {
            throw new PaymentException('随行付' . $scene . '返回商户编号不匹配', 40200);
        }
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
        $actual = $this->yuanToCents($yuan, $scene);
        if ($actual !== $expectedCents) {
            throw new PaymentException($scene . '金额与支付单不一致', 40200, [
                'actual_cents' => $actual,
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
        $value = trim((string) $yuan);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new PaymentException($scene . '金额格式无效', 40200);
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    private function positiveCents(mixed $value, string $scene): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new PaymentException($scene . '必须为正整数分', 40200);
        }

        return (int) $value;
    }

    /**
     * 校验渠道币种。
     *
     * @param array<string, mixed> $data 渠道响应或已验签通知
     * @param string $scene 用于异常提示的业务场景
     */
    private function assertCurrency(array $data, string $scene): void
    {
        $currency = $this->firstText($data['currency'] ?? '', $data['currencyCode'] ?? '', $data['feeType'] ?? '');
        if ($currency !== '' && strtoupper($currency) !== 'CNY') {
            throw new PaymentException($scene . '币种不是 CNY', 40200, ['currency' => strtoupper($currency)]);
        }
    }

    private function tradeStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'FAIL', 'FAILED' => PaymentPluginStatusConstant::FAILED,
            'CANCELED', 'CLOSED' => PaymentPluginStatusConstant::CLOSED,
            'PAYING', 'PROCESSING', '' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
    }

    /**
     * 查询支付对账状态。
     *
     * 仅供失败后的状态收敛使用，查询异常会折叠为 UNKNOWN，不能据此确认关闭成功。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 查单结果或 UNKNOWN 占位结果
     */
    private function safeQueryForReconciliation(array $order): array
    {
        try {
            return $this->query($order);
        } catch (Throwable) {
            return ['success' => false, 'status' => PaymentPluginStatusConstant::UNKNOWN];
        }
    }

    /**
     * 按申请或查询的固定字段解析已验签退款响应。
     *
     * 申请的 amt/origOrderNo 与查询的 refundAmount/origOrdNo 是不同接口字段，不互相兜底。
     * 申请成功必须有原交易、金额和 uuid；查询始终要求退款请求号、uuid、金额和原金额。
     *
     * @param array<string, mixed> $refund 标准插件退款参数，金额单位分
     * @param array<string, mixed> $data SDK 已验签并关联请求号的业务响应
     * @param bool $isQuery 是否为退款查询响应
     * @return array<string, mixed> 可信标准结果；未知状态不推断终态
     * @throws PaymentUncertainException 响应缺少必需事实或与原退款不一致
     */
    private function parseRefundResult(array $refund, array $data, bool $isQuery): array
    {
        $refundNo = (string) $refund['refund_no'];
        $payNo = (string) $refund['pay_no'];
        $refundAmount = (int) $refund['refund_amount'];
        $channelStatus = (string) ($data['tranSts'] ?? '');
        $status = match ($channelStatus) {
            'REFUNDSUC' => PaymentPluginStatusConstant::SUCCESS,
            'REFUNDFAIL' => PaymentPluginStatusConstant::FAILED,
            'REFUNDING' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        try {
            $this->assertBusinessSuccess($data, $isQuery ? '退款查询' : '退款');
            $this->assertResponseMerchant($data, '退款');
            $responseRefundNo = (string) ($data['ordNo'] ?? '');
            if (($isQuery || $responseRefundNo !== '') && !hash_equals($refundNo, $responseRefundNo)) {
                throw new PaymentException('随行付退款响应缺少或返回错误退款单号', 40200);
            }
            $originalField = $isQuery ? 'origOrdNo' : 'origOrderNo';
            if ((!$isQuery && $status === PaymentPluginStatusConstant::SUCCESS) || (string) ($data[$originalField] ?? '') !== '') {
                if (!hash_equals($payNo, (string) ($data[$originalField] ?? ''))) {
                    throw new PaymentException('随行付退款响应原交易标识缺失或不匹配', 40200);
                }
            }
            $amountField = $isQuery ? 'refundAmount' : 'amt';
            if ($isQuery || $status === PaymentPluginStatusConstant::SUCCESS || array_key_exists($amountField, $data)) {
                $this->assertAmountMatches($data[$amountField] ?? null, $refundAmount, '随行付退款');
            }
            if ($isQuery) {
                $this->assertAmountMatches($data['totalAmount'] ?? null, (int) $refund['amount'], '随行付退款原订单');
            }
            $channelRefundNo = (string) ($data['uuid'] ?? '');
            if (($isQuery || $status === PaymentPluginStatusConstant::SUCCESS) && $channelRefundNo === '') {
                throw new PaymentException('随行付退款响应缺少 uuid', 40200);
            }
            $storedRefundNo = (string) $refund['chan_refund_no'];
            if ($storedRefundNo !== '' && $channelRefundNo !== '' && !hash_equals($storedRefundNo, $channelRefundNo)) {
                throw new PaymentException('随行付退款响应渠道号与原退款不匹配', 40200);
            }
        } catch (PaymentException $e) {
            throw new PaymentUncertainException($e->getMessage(), 40200,
                array_replace($e->getData(), ['refund_no' => $refundNo]));
        }

        // 查询成功的 bizMsg 不是退款失败原因，查询失败原因只取官方 tradeMsg。
        $failureReason = (string) ($data[$isQuery ? 'tradeMsg' : 'bizMsg'] ?? '');
        return [
            'status' => $status, 'refund_no' => $refundNo, 'pay_no' => $payNo, 'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo, 'channel_status' => $channelStatus,
            'message' => match ($status) {
                PaymentPluginStatusConstant::SUCCESS => '随行付退款成功',
                PaymentPluginStatusConstant::FAILED => '随行付退款失败' . ($failureReason !== '' ? '：' . $failureReason : ''),
                PaymentPluginStatusConstant::PENDING => '随行付退款处理中',
                default => '随行付退款状态尚未确认',
            },
        ];
    }

    /**
     * 获取按当前接口版本、机构和商户密钥初始化的 SDK 客户端。
     */
    private function client(): SuixingpayClient
    {
        if ($this->client === null) {
            $this->client = new SuixingpayClient([
                'suixingpay_org_id' => $this->configText('suixingpay_org_id'),
                'suixingpay_merchant_no' => $this->configText('suixingpay_merchant_no'),
                'suixingpay_platform_public_key' => $this->configText('suixingpay_platform_public_key'),
                'suixingpay_merchant_private_key' => $this->configText('suixingpay_merchant_private_key'),
                'suixingpay_sandbox' => $this->configBool('suixingpay_sandbox'),
                'suixingpay_api_base_url' => $this->configText('suixingpay_api_base_url'),
            ]);
        }

        return $this->client;
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

        $products = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $products
        )));
        if (in_array('wxpay_jsapi', $products, true)) {
            $products = array_merge($products, [self::PRODUCT_WXPAY_MP, self::PRODUCT_WXPAY_MINI]);
            $products = array_values(array_filter($products, static fn (string $product): bool => $product !== 'wxpay_jsapi'));
        }

        return array_values(array_unique($products));
    }

    /**
     * 构建已开通产品配置字段。
     *
     * @return array<string, mixed>
     */
    private function productField(): array
    {
        $field = $this->directPaymentEnabledProductsField([
            self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
            self::PRODUCT_ALIPAY_JSAPI => '支付宝JSAPI',
            self::PRODUCT_WXPAY_SCAN => '微信扫码',
            self::PRODUCT_WXPAY_MP => '微信公众号JSAPI',
            self::PRODUCT_WXPAY_MINI => '微信小程序JSAPI',
            self::PRODUCT_WXPAY_APPLET_PLUGIN => '微信小程序支付插件',
            self::PRODUCT_WXPAY_APPLET_CASHIER => '微信半屏小程序收银台',
            self::PRODUCT_BANK_SCAN => '银联/云闪付扫码',
        ]);
        $field['value'] = [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_WXPAY_SCAN, self::PRODUCT_BANK_SCAN];

        return $field;
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
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    private function apiProfile(): string
    {
        return $this->configText('suixingpay_api_profile', self::PROFILE_CURRENT);
    }

    /**
     * 构建脱敏诊断上下文。
     *
     * 仅暴露业务码、截断后的业务信息、状态和请求 ID，不把签名或密钥材料带入异常上下文。
     *
     * @param array<string, mixed> $data 渠道响应
     *
     * @return array<string, scalar>
     */
    private function diagnosticContext(array $data): array
    {
        return array_filter([
            'biz_code' => (string) ($data['bizCode'] ?? ''),
            'biz_msg' => mb_strcut((string) ($data['bizMsg'] ?? ''), 0, 200, 'UTF-8'),
            'channel_status' => (string) ($data['tranSts'] ?? $data['refundStatus'] ?? ''),
            'request_id' => $this->client()->lastRequestId(),
        ], static fn (mixed $value): bool => $value !== '');
    }

    private function gatewayTime(mixed $value): ?string
    {
        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^\d{14}$/', $time) === 1) {
            return sprintf('%s-%s-%s %s:%s:%s',
                substr($time, 0, 4), substr($time, 4, 2), substr($time, 6, 2),
                substr($time, 8, 2), substr($time, 10, 2), substr($time, 12, 2)
            );
        }

        return $time;
    }

    private function requiredText(mixed $value, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new PaymentException($message, 40200);
        }

        return $value;
    }

    private function requiredConfig(string $key, string $label): string
    {
        $value = $this->configText($key);
        if ($value === '') {
            throw new PaymentException('随行付' . $label . '未配置', 40200);
        }

        return $value;
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
     * 提取渠道交易号。
     *
     * @param array<string, mixed> $data 渠道响应或已验签通知
     *
     * @return array<int, string>
     */
    private function tradeNumbers(array $data): array
    {
        return array_values(array_unique(array_filter([
            $this->firstText($data['uuid'] ?? ''),
            $this->firstText($data['sxfUuid'] ?? ''),
            $this->firstText($data['transactionId'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));
    }

    private function configText(string $key, string $default = ''): string
    {
        $value = $this->getConfig($key, null);
        if (($value === null || trim((string) $value) === '') && isset(self::LEGACY_CONFIG_ALIASES[$key])) {
            $value = $this->getConfig(self::LEGACY_CONFIG_ALIASES[$key], $default);
        }

        return trim((string) ($value ?? $default));
    }

    private function configBool(string $key): bool
    {
        $value = $this->getConfig($key, null);
        if ($value === null && isset(self::LEGACY_CONFIG_ALIASES[$key])) {
            $value = $this->getConfig(self::LEGACY_CONFIG_ALIASES[$key], false);
        }

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }
}
