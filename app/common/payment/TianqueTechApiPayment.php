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
use app\common\sdk\tianquetech\TianqueTechClient;
use app\common\sdk\tianquetech\TianqueTechSdkException;
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
 * 天阙科技 OpenAPI 支付插件。
 *
 * 负责扫码、JSAPI、托管小程序支付以及查单、关单、退款和支付通知适配。
 * 接口路径按当前协议或显式配置的兼容协议档位选择，不会在运行时自动猜测版本。
 */
class TianqueTechApiPayment extends BasePayment implements
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

    private ?TianqueTechClient $client = null;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'tianquetech_api',
        'name' => '天阙科技OpenAPI支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '2.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造天阙支付插件。
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
                'field' => 'api_profile',
                'title' => '接口产品版本',
                'value' => self::PROFILE_CURRENT,
                'options' => [
                    ['label' => '天阙当前文档版', 'value' => self::PROFILE_CURRENT],
                    ['label' => '彩虹旧产品版', 'value' => self::PROFILE_RAINBOW_LEGACY],
                ],
                'validate' => [['required' => true, 'message' => '接口产品版本不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'org_id',
                'title' => '机构编号',
                'value' => '',
                'validate' => [['required' => true, 'message' => '机构编号不能为空']],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户编号',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户编号不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '天阙平台公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [['required' => true, 'message' => '平台公钥不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '天阙商户私钥（PKCS#8/PKCS#1）',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [['required' => true, 'message' => '商户私钥不能为空']],
            ],
            $this->productField(),
            ['type' => 'input', 'field' => 'wechat_mp_app_id', 'title' => '微信公众号 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'wechat_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wechat_mini_app_id', 'title' => '微信小程序 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'wechat_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wechat_mini_launch_path', 'title' => '微信小程序承接路径', 'value' => ''],
            ['type' => 'input', 'field' => 'tianque_applet_plugin_app_id', 'title' => '天阙小程序支付插件 AppID', 'value' => ''],
            [
                'type' => 'radio',
                'field' => 'wechat_mini_env_version',
                'title' => '微信小程序版本',
                'value' => 'release',
                'options' => [
                    ['label' => '正式版', 'value' => 'release'],
                    ['label' => '体验版', 'value' => 'trial'],
                    ['label' => '开发版', 'value' => 'develop'],
                ],
            ],
            ['type' => 'input', 'field' => 'alipay_app_id', 'title' => '支付宝应用 AppID', 'value' => ''],
            ['type' => 'textarea', 'field' => 'alipay_app_private_key', 'title' => '支付宝应用私钥', 'value' => '', 'props' => ['rows' => 4]],
            ['type' => 'textarea', 'field' => 'alipay_public_key', 'title' => '支付宝公钥', 'value' => '', 'props' => ['rows' => 4]],
            ['type' => 'input', 'field' => 'alipay_mini_launch_path', 'title' => '支付宝小程序承接路径', 'value' => ''],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '测试环境',
                'value' => false,
                'props' => ['checkedText' => '测试', 'uncheckedText' => '生产'],
            ],
            [
                'type' => 'input',
                'field' => 'api_base_url',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => ['placeholder' => '留空使用天阙默认网关'],
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

        foreach (['org_id' => '机构编号', 'merchant_no' => '商户编号', 'platform_public_key' => '平台公钥', 'merchant_private_key' => '商户私钥'] as $field => $label) {
            if ($this->configText($field) === '') {
                throw new PaymentException('天阙' . $label . '不能为空', 40200);
            }
        }
        if (!in_array($this->apiProfile(), [self::PROFILE_CURRENT, self::PROFILE_RAINBOW_LEGACY], true)) {
            throw new PaymentException('天阙接口产品版本配置无效', 40200);
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
            throw new PaymentException('天阙已开通产品配置无效', 40200);
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

            return [
                'provider' => 'alipay',
                'product' => self::PRODUCT_ALIPAY_JSAPI,
                'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
                'auth_type' => 'alipay_mini',
                'identity_field' => 'buyer_id',
                'app_id' => $this->configText('alipay_app_id'),
                'mini_path' => $this->configText('alipay_mini_launch_path'),
                'scope' => 'auth_base',
                '_alipay_config' => [
                    'mode' => 'key',
                    'app_id' => $this->configText('alipay_app_id'),
                    'private_key' => $this->configText('alipay_app_private_key'),
                    'alipay_public_key' => $this->configText('alipay_public_key'),
                    'sandbox' => $this->configBool('sandbox'),
                ],
                'message' => '天阙支付宝 JSAPI 仅接受当前应用作用域的 buyer_id（支付宝 userId）',
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

        return [
            'provider' => 'wxpay',
            'product' => $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP,
            'channel_product' => $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'sub_openid',
            'identity_aliases' => $mini ? [] : ['openid'],
            'app_id' => $mini ? $this->configText('wechat_mini_app_id') : $this->configText('wechat_mp_app_id'),
            'scope' => 'snsapi_base',
            '_app_secret' => $mini ? $this->configText('wechat_mini_app_secret') : $this->configText('wechat_mp_app_secret'),
            'mini_path' => $mini ? $this->configText('wechat_mini_launch_path') : '',
            'env_version' => $mini ? $this->configText('wechat_mini_env_version', 'release') : '',
            'mini_launch_type' => $mini
                ? (strtolower(trim((string) ($order['_env'] ?? ''))) === 'wechat' ? 'url_link' : 'url_scheme')
                : '',
            'message' => $mini
                ? '天阙微信小程序支付需要当前小程序作用域的 mini_openid'
                : '天阙微信公众号支付需要当前公众号作用域的 sub_openid/openid',
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
        return $this->executeDirectPaymentProduct($order, $this->paymentHandlers($order), '天阙');
    }

    /**
     * 查询支付订单。
     *
     * 查单响应须匹配本地订单号、商户号、币种及已知金额。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '天阙查单缺少支付单号');
        try {
            $data = $this->client()->submit(TianqueTechClient::PATH_TRADE_QUERY, [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => $payNo,
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentException('天阙查单失败：' . $e->getMessage(), 40200, [
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
            throw new PaymentException('天阙成功查单响应缺少原交易金额', 40200, ['pay_no' => $payNo]);
        }
        if (array_key_exists('oriTranAmt', $data) && $expectedAmount >= 0) {
            $this->assertAmountMatches($data['oriTranAmt'], $expectedAmount, '天阙查单');
        }
        $this->assertCurrency($data, '天阙查单');

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $expectedAmount : null,
            'chan_order_no' => (string) ($data['ordNo'] ?? $payNo),
            'chan_trade_no' => $this->firstText(
                $data['uuid'] ?? '',
                $data['sxfUuid'] ?? '',
                $data['transactionId'] ?? '',
                $order['chan_trade_no'] ?? ''
            ),
            'channel_status' => strtoupper((string) ($data['tranSts'] ?? '')),
            'message' => (string) ($data['bizMsg'] ?? $data['tranSts'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $this->gatewayTime($data['payTime'] ?? '') : null,
            'request_id' => $this->client()->lastRequestId(),
        ];
    }

    /**
     * 关闭支付订单。
     *
     * 渠道未返回对应协议的关闭终态时保持待确认；请求异常时会查单，只有查得关闭终态才返回成功。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     *
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        $payNo = $this->requiredText($order['pay_no'] ?? '', '天阙关单缺少支付单号');
        $path = $this->apiProfile() === self::PROFILE_CURRENT
            ? TianqueTechClient::PATH_CLOSE_CURRENT
            : TianqueTechClient::PATH_CANCEL_LEGACY;
        try {
            $data = $this->client()->submit($path, [
                'mno' => $this->configText('merchant_no'),
                'origOrderNo' => $payNo,
            ]);
            $this->assertBusinessSuccess($data, '关单');
            $this->assertResponseOrder($data, $payNo, '关单', ['origOrderNo', 'ordNo']);
            $this->assertResponseMerchant($data, '关单');
            $channelStatus = strtoupper((string) ($data['tranSts'] ?? ''));
            $expectedStatus = $this->apiProfile() === self::PROFILE_CURRENT ? 'CLOSED' : 'CANCELED';
            if (!hash_equals($expectedStatus, $channelStatus)) {
                return [
                    'status' => PaymentPluginStatusConstant::PENDING,
                    'pay_no' => $payNo,
                    'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
                    'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                    'channel_status' => $channelStatus,
                    'message' => '天阙关单结果尚未确认',
                ];
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
        } catch (TianqueTechSdkException|PaymentException $e) {
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
            throw new PaymentException('天阙关单失败：' . $e->getMessage(), 40200, ['pay_no' => $payNo]);
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
            'mno' => $this->configText('merchant_no'),
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
            $data = $this->client()->submit(TianqueTechClient::PATH_REFUND, $payload);
        } catch (TianqueTechSdkException $e) {
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
                throw new PaymentUncertainException('天阙退款请求未确认：' . $requestError . '；查询未确认：' . $e->getMessage(), 40200,
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
     * 查询天阙退款状态并核对本次退款的金额与标识。
     *
     * 本方法只读上游，不重发退款；failed 是可信查询终态，unknown 不推断失败。
     */
    public function queryRefund(array $refund): array
    {
        $refundNo = (string) $refund['refund_no'];

        try {
            $data = $this->client()->submit(TianqueTechClient::PATH_REFUND_QUERY, [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => $refundNo,
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentUncertainException('天阙退款查询失败：' . $e->getMessage(), 40200, [
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
            throw new PaymentException('天阙回调不是合法 JSON', 40200);
        }
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('天阙回调验签失败', 40200);
        }
        if ((string) ($payload['bizCode'] ?? '') !== '0000') {
            throw new PaymentException('天阙回调不是支付成功通知', 40200, ['biz_code' => (string) ($payload['bizCode'] ?? '')]);
        }

        $payNo = $this->requiredText($payload['ordNo'] ?? '', '天阙回调缺少订单号');
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('天阙回调支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('天阙回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }
        $mno = trim((string) ($payload['mno'] ?? ''));
        if ($mno === '' || !hash_equals($this->configText('merchant_no'), $mno)) {
            throw new PaymentException('天阙回调商户编号不匹配', 40200, ['pay_no' => $payNo]);
        }
        $orgId = trim((string) ($payload['orgId'] ?? ''));
        if ($orgId !== '' && !hash_equals($this->configText('org_id'), $orgId)) {
            throw new PaymentException('天阙回调机构编号不匹配', 40200, ['pay_no' => $payNo]);
        }
        $this->assertAmountMatches($payload['amt'] ?? '', (int) $payOrder->pay_amount, '天阙回调');
        $this->assertCurrency($payload, '天阙回调');
        $this->assertNotifyProduct($payload, $payOrder);

        $tradeNo = $this->firstText($payload['uuid'] ?? '', $payload['sxfUuid'] ?? '', $payload['transactionId'] ?? '');
        if ($tradeNo === '') {
            throw new PaymentException('天阙成功回调缺少渠道交易号', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('天阙回调渠道交易号与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }

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
                        default => throw new PaymentException('天阙 JSAPI 不支持当前支付方式', 40200),
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
            default => throw new PaymentException('天阙扫码不支持当前支付方式', 40200),
        };
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品代码
     * @param string $payType 天阙支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function scanPay(array $order, string $product, string $payType): array
    {
        $path = $this->apiProfile() === self::PROFILE_CURRENT
            ? TianqueTechClient::PATH_ACTIVE_SCAN_CURRENT
            : TianqueTechClient::PATH_ACTIVE_SCAN_LEGACY;
        try {
            $data = $this->client()->submit($path, [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['amount']),
                'payType' => $payType,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentException('天阙扫码下单失败：' . $e->getMessage(), 40200);
        }

        $this->assertBusinessSuccess($data, '扫码下单');
        $url = trim((string) ($data['payUrl'] ?? ''));
        if ($url === '') {
            throw new PaymentException('天阙扫码下单未返回支付地址', 40200, $this->diagnosticContext($data));
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
     * @param string $payType 天阙支付方式代码
     *
     * @return array<string, mixed> 标准 JSAPI 待支付结果
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $payment = $this->paymentPayload($order);
        $mini = $product === self::PRODUCT_WXPAY_MINI;
        if ($payType === 'ALIPAY') {
            $userId = $this->firstText($payment['buyer_id'] ?? '');
            $subAppId = '';
            $payWay = '02';
            if ($userId === '') {
                throw new PaymentException('天阙支付宝 JSAPI 缺少 buyer_id（不接受 buyer_open_id）', 40200);
            }
        } elseif ($mini) {
            $userId = $this->firstText($payment['mini_openid'] ?? '');
            $subAppId = $this->wechatSubAppId($payment, true);
            $payWay = '03';
            if ($userId === '') {
                throw new PaymentException('天阙微信小程序支付缺少 mini_openid', 40200);
            }
        } else {
            $userId = $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
            $subAppId = $this->wechatSubAppId($payment, false);
            $payWay = '02';
            if ($userId === '') {
                throw new PaymentException('天阙微信公众号支付缺少 sub_openid/openid', 40200);
            }
        }

        try {
            $data = $this->client()->submit(TianqueTechClient::PATH_JSAPI_SCAN, [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['amount']),
                'payType' => $payType,
                'payWay' => $payWay,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'subAppid' => $subAppId,
                'userId' => $userId,
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentException('天阙 JSAPI 下单失败：' . $e->getMessage(), 40200);
        }

        $this->assertBusinessSuccess($data, 'JSAPI下单');
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
                    throw new PaymentException('天阙微信 JSAPI 响应缺少 ' . $field, 40200, $this->diagnosticContext($data));
                }
            }
        } else {
            $tradeNo = trim((string) ($data['source'] ?? ''));
            if ($tradeNo === '') {
                throw new PaymentException('天阙支付宝 JSAPI 响应缺少 source', 40200, $this->diagnosticContext($data));
            }
            $params = ['tradeNO' => $tradeNo];
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => ltrim(TianqueTechClient::PATH_JSAPI_SCAN, '/'),
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['uuid'] ?? '', $data['sxfUuid'] ?? '', $data['transactionId'] ?? ''),
        ]);
    }

    /**
     * 发起天阙托管小程序支付。
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
        $source = $product === self::PRODUCT_WXPAY_APPLET_CASHIER ? '01' : '00';
        try {
            $data = $this->client()->submit(TianqueTechClient::PATH_APPLET_SCAN_PRE, [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['amount']),
                'appletSource' => $source,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentException('天阙小程序收银台下单失败：' . $e->getMessage(), 40200);
        }

        $this->assertBusinessSuccess($data, '小程序收银台下单');
        if ($source === '00') {
            $appId = $this->configText('tianque_applet_plugin_app_id');
            $key = trim((string) ($data['key'] ?? ''));
            $amount = trim((string) ($data['amt'] ?? ''));
            if ($appId === '' || $key === '' || $amount === '') {
                throw new PaymentException('天阙小程序支付插件响应或插件 AppID 配置不完整', 40200, $this->diagnosticContext($data));
            }
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
                throw new PaymentException('天阙半屏小程序收银台响应缺少 appId/path', 40200, $this->diagnosticContext($data));
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
            'pay_action' => ltrim(TianqueTechClient::PATH_APPLET_SCAN_PRE, '/'),
            'pay_params' => [
                '_page' => 'page',
                'execution_context' => 'wechat_mini_program',
                'launch_type' => $source === '00'
                    ? 'wechat_mini_program_plugin'
                    : 'wechat_mini_program_half_screen',
                'params' => $params,
                'description' => $source === '00'
                    ? '请由微信小程序容器使用天阙支付插件 AppID、key 和 amt 发起支付。'
                    : '请由微信小程序容器使用天阙返回的 appId 和 path 打开半屏收银台。',
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => $this->firstText($data['uuid'] ?? '', $data['sxfUuid'] ?? ''),
        ]);
    }

    /**
     * 获取微信子应用 ID。
     *
     * 若配置和订单同时携带 AppID，两者必须一致，避免复用其他应用作用域的用户身份。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param bool $mini 是否读取微信小程序 AppID
     */
    private function wechatSubAppId(array $payment, bool $mini): string
    {
        $configured = $mini ? $this->configText('wechat_mini_app_id') : $this->configText('wechat_mp_app_id');
        $provided = $mini
            ? $this->firstText($payment['mini_app_id'] ?? '', $payment['sub_appid'] ?? '')
            : $this->firstText($payment['sub_appid'] ?? '');
        if ($configured !== '' && $provided !== '' && !hash_equals($configured, $provided)) {
            throw new PaymentException('天阙微信 subAppid 与当前通道应用配置不一致', 40200);
        }
        $appId = $this->firstText($configured, $provided);
        if ($appId === '') {
            throw new PaymentException($mini ? '天阙微信小程序 AppID 未配置' : '天阙微信公众号 AppID 未配置', 40200);
        }

        return $appId;
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
            default => throw new PaymentException('天阙回调无法识别支付单产品快照', 40200, ['pay_no' => (string) $payOrder->pay_no]),
        };
        $payType = strtoupper(trim((string) ($payload['payType'] ?? '')));
        if ($payType === '' || !hash_equals($expected[0], $payType)) {
            throw new PaymentException('天阙回调支付类型与支付单产品不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
        if ($expected[1] !== null) {
            $payWay = trim((string) ($payload['payWay'] ?? ''));
            if (!hash_equals($expected[1], $payWay)) {
                throw new PaymentException('天阙回调 payWay 与公众号/小程序产品不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
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
        $code = trim((string) ($data['bizCode'] ?? '0000'));
        if ($code !== '0000') {
            throw new PaymentException('天阙' . $scene . '失败：' . (string) ($data['bizMsg'] ?? $code), 40200, $this->diagnosticContext($data));
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
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $actual = trim((string) $data[$field]);
            if ($actual !== '' && !hash_equals($expected, $actual)) {
                throw new PaymentException('天阙' . $scene . '返回订单号不匹配', 40200, ['expected_order_no' => $expected]);
            }
            return;
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
        if ($mno !== '' && !hash_equals($this->configText('merchant_no'), $mno)) {
            throw new PaymentException('天阙' . $scene . '返回商户编号不匹配', 40200);
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
                throw new PaymentException('天阙退款响应缺少或返回错误退款单号', 40200);
            }
            $originalField = $isQuery ? 'origOrdNo' : 'origOrderNo';
            if ((!$isQuery && $status === PaymentPluginStatusConstant::SUCCESS) || (string) ($data[$originalField] ?? '') !== '') {
                if (!hash_equals($payNo, (string) ($data[$originalField] ?? ''))) {
                    throw new PaymentException('天阙退款响应原交易标识缺失或不匹配', 40200);
                }
            }
            $amountField = $isQuery ? 'refundAmount' : 'amt';
            if ($isQuery || $status === PaymentPluginStatusConstant::SUCCESS || array_key_exists($amountField, $data)) {
                $this->assertAmountMatches($data[$amountField] ?? null, $refundAmount, '天阙退款');
            }
            if ($isQuery) {
                $this->assertAmountMatches($data['totalAmount'] ?? null, (int) $refund['amount'], '天阙退款原订单');
            }
            $channelRefundNo = (string) ($data['uuid'] ?? '');
            if (($isQuery || $status === PaymentPluginStatusConstant::SUCCESS) && $channelRefundNo === '') {
                throw new PaymentException('天阙退款响应缺少 uuid', 40200);
            }
            $storedRefundNo = (string) $refund['chan_refund_no'];
            if ($storedRefundNo !== '' && $channelRefundNo !== '' && !hash_equals($storedRefundNo, $channelRefundNo)) {
                throw new PaymentException('天阙退款响应渠道号与原退款不匹配', 40200);
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
                PaymentPluginStatusConstant::SUCCESS => '天阙退款成功',
                PaymentPluginStatusConstant::FAILED => '天阙退款失败' . ($failureReason !== '' ? '：' . $failureReason : ''),
                PaymentPluginStatusConstant::PENDING => '天阙退款处理中',
                default => '天阙退款状态尚未确认',
            },
        ];
    }

    /**
     * 获取按当前接口版本、机构和商户密钥初始化的 SDK 客户端。
     */
    private function client(): TianqueTechClient
    {
        if ($this->client === null) {
            $this->client = new TianqueTechClient([
                'org_id' => $this->configText('org_id'),
                'merchant_no' => $this->configText('merchant_no'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'sandbox' => $this->configBool('sandbox'),
                'api_base_url' => $this->configText('api_base_url'),
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

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $products
        )));
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
        return $this->configText('api_profile', self::PROFILE_CURRENT);
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

    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function configText(string $key, string $default = ''): string
    {
        return trim((string) $this->getConfig($key, $default));
    }

    private function configBool(string $key): bool
    {
        return in_array($this->getConfig($key, false), [true, 1, '1', 'true', 'on'], true);
    }
}
