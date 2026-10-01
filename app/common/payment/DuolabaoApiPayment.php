<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\duolabao\DuolabaoClient;
use app\common\sdk\duolabao\DuolabaoSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\repository\payment\trade\PayOrderRepository;
use JsonException;
use support\Request;
use support\Response;

/**
 * 哆啦宝支付 API 插件。
 *
 * 适配 rainbow_legacy 合同的聚合二维码、支付宝 JSAPI、微信 JSAPI/小程序和退款接口，
 * 负责精确区分微信主商户与子商户身份作用域，并校验通知所属商户、门店、订单和金额。
 * 该合同未核验主动查单与关单能力，因此插件明确拒绝这两类操作。
 */
class DuolabaoApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const CONTRACT_PROFILE = 'rainbow_legacy';
    private const PRODUCT_ALIPAY_JSAPI = 'ALIPAY_JSAPI';
    private const PRODUCT_WX_JSAPI = 'WX_JSAPI';
    private const PRODUCT_QRCODE_TRAD = 'QRCODE_TRAD';
    private const PRODUCTS = [
        self::PRODUCT_ALIPAY_JSAPI,
        self::PRODUCT_WX_JSAPI,
        self::PRODUCT_QRCODE_TRAD,
    ];

    private ?DuolabaoClient $client = null;
    private PayOrderRepository $payOrderRepository;

    /**
     * 插件元信息。
     *
     * 配置表单由 getConfigSchema() 动态生成，以便声明通道实际开通的产品和身份配置。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'duolabao_api',
        'name' => '哆啦宝支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'http://www.duolabao.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造哆啦宝支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付单仓库；未注入时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
    }

    /**
     * 初始化支付插件。
     *
     * @param array<string, mixed> $channelConfig 通道配置
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = null;

        if ($this->configText('contract_profile', self::CONTRACT_PROFILE) !== self::CONTRACT_PROFILE) {
            throw new PaymentException('哆啦宝仅支持 rainbow_legacy 合同', 40200);
        }
        foreach (['customer_num', 'shop_num', 'access_key', 'secret_key'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentException('哆啦宝通道配置不完整', 40200, ['missing_field' => $field]);
            }
        }
        $products = $this->enabledProducts();
        if ($products === [] || array_diff($products, self::PRODUCTS) !== []) {
            throw new PaymentException('哆啦宝已开通产品配置无效', 40200);
        }
        if ($this->configText('wx_platform_app_id') !== ''
            && in_array($this->configText('wx_platform_app_id'), [
                $this->configText('wx_mp_app_id'),
                $this->configText('wx_mini_app_id'),
            ], true)) {
            throw new PaymentException('哆啦宝微信主商户 appId 与子商户 subAppId 不能相同', 40200);
        }
    }

    /**
     * 获取插件配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'contract_profile', 'title' => '协议档案', 'value' => self::CONTRACT_PROFILE, 'validate' => [['required' => true, 'message' => '协议档案不能为空']]],
            ['type' => 'input', 'field' => 'agent_num', 'title' => '代理商编号', 'value' => ''],
            ['type' => 'input', 'field' => 'customer_num', 'title' => '客户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '客户编号不能为空']]],
            ['type' => 'input', 'field' => 'shop_num', 'title' => '门店编号', 'value' => '', 'validate' => [['required' => true, 'message' => '门店编号不能为空']]],
            ['type' => 'input', 'field' => 'access_key', 'title' => 'AccessKey', 'value' => '', 'validate' => [['required' => true, 'message' => 'AccessKey不能为空']]],
            ['type' => 'password', 'field' => 'secret_key', 'title' => 'SecretKey', 'value' => '', 'validate' => [['required' => true, 'message' => 'SecretKey不能为空']]],
            ['type' => 'input', 'field' => 'wx_platform_app_id', 'title' => '微信主商户/服务商 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'wx_platform_app_secret', 'title' => '微信主商户/服务商 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wx_mp_app_id', 'title' => '微信公众号 AppID（直连或子商户）', 'value' => ''],
            ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wx_mini_app_id', 'title' => '微信小程序 AppID（直连或子商户）', 'value' => ''],
            ['type' => 'password', 'field' => 'wx_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            ['type' => 'input', 'field' => 'wx_mini_launch_path', 'title' => '微信小程序支付页路径', 'value' => ''],
            ['type' => 'input', 'field' => 'alipay_oauth_app_id', 'title' => '支付宝授权 AppID', 'value' => ''],
            ['type' => 'textarea', 'field' => 'alipay_oauth_private_key', 'title' => '支付宝应用私钥', 'value' => ''],
            ['type' => 'textarea', 'field' => 'alipay_oauth_public_key', 'title' => '支付宝公钥', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WX_JSAPI => '微信 JSAPI/小程序',
                self::PRODUCT_QRCODE_TRAD => '聚合二维码',
            ]),
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
        $product = $this->selectedProduct($order);
        $result = match ($product) {
            self::PRODUCT_QRCODE_TRAD => $this->qrcodePay($order),
            self::PRODUCT_ALIPAY_JSAPI => $this->alipayJsapiPay($order),
            self::PRODUCT_WX_JSAPI => $this->wxJsapiPay($order, $this->isMiniOrder($order)),
            default => throw new PaymentDefinitiveException('哆啦宝支付产品无效', 40200),
        };

        return $result;
    }

    /**
     * 声明支付所需的用户身份。
     *
     * 支付宝需要 buyer_id；微信根据直连或服务商作用域分别要求 openid、sub_openid
     * 或 mini_openid。授权流程由平台身份服务执行，本方法只返回身份要求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；已有精确作用域身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->selectedProduct($order);
        if ($product === self::PRODUCT_QRCODE_TRAD) {
            return null;
        }
        if ($product === self::PRODUCT_ALIPAY_JSAPI) {
            $payment = $this->paymentPayload($order);
            if (trim((string) ($payment['buyer_id'] ?? '')) !== '') {
                return null;
            }
            foreach (['alipay_oauth_app_id', 'alipay_oauth_private_key', 'alipay_oauth_public_key'] as $field) {
                if ($this->configText($field) === '') {
                    throw new PaymentException('哆啦宝支付宝 JSAPI 缺少 buyer_id，且授权配置不完整', 40200);
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
                ],
                'message' => '哆啦宝支付宝 JSAPI 需要 buyer_id',
            ];
        }

        $mini = $this->isMiniOrder($order);
        $scope = $this->wxScope($order, $mini);
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment[$scope['identity_field']] ?? '')) !== '') {
            return null;
        }
        if ($scope['identity_app_id'] === '' || $scope['identity_app_secret'] === '') {
            throw new PaymentException($mini
                ? '哆啦宝微信小程序缺少 mini_openid，且小程序授权配置不完整'
                : '哆啦宝微信公众号缺少精确作用域身份，且公众号授权配置不完整', 40200);
        }

        return [
            'provider' => 'wxpay',
            'product' => $mini ? 'mini' : 'mp',
            'channel_product' => self::PRODUCT_WX_JSAPI,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $scope['identity_field'],
            'app_id' => $scope['identity_app_id'],
            '_app_secret' => $scope['identity_app_secret'],
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wx_mini_launch_path') : '',
            'env_version' => 'release',
            'message' => $mini
                ? '哆啦宝微信小程序需要 mini_openid'
                : '哆啦宝微信公众号需要 ' . $scope['identity_field'],
        ];
    }

    /**
     * 当前 rainbow_legacy 合同没有已核验的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('哆啦宝 rainbow_legacy 暂不支持主动查单', 40200);
    }

    /**
     * 当前 rainbow_legacy 合同没有已核验的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('哆啦宝 rainbow_legacy 暂不支持关单', 40200);
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $payNo = $this->requiredOrderText($order, 'pay_no', '哆啦宝退款缺少原支付单号');
        $refundNo = $this->requiredOrderText($order, 'refund_no', '哆啦宝退款缺少退款单号');
        $originalOrderNo = $this->requiredOrderText($order, 'chan_order_no', '哆啦宝退款缺少原渠道订单号');
        $refundAmount = (int) ($order['refund_amount'] ?? 0);
        if ($refundAmount <= 0) {
            throw new PaymentDefinitiveException('哆啦宝退款金额无效', 40200);
        }

        try {
            $data = $this->client()->post('/api/refundByRequestNum', [
                'requestVersion' => 'V4.0',
                'agentNum' => $this->configText('agent_num'),
                'customerNum' => $this->configText('customer_num'),
                'shopNum' => $this->configText('shop_num'),
                'requestNum' => $payNo,
                'refundPartAmount' => FormatHelper::amount($refundAmount),
                'refundRequestNum' => $refundNo,
                'extMap' => ['refund_status_type' => '1'],
            ]);
        } catch (DuolabaoSdkException $e) {
            $this->throwSdkException('哆啦宝退款', $e);
        }

        $responseOrderNo = trim((string) ($data['orderNum'] ?? ''));
        if ($responseOrderNo === '' || !hash_equals($originalOrderNo, $responseOrderNo)) {
            throw new PaymentUncertainException('哆啦宝退款响应原渠道订单号不一致', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        if (isset($data['refundRequestNum'])
            && !hash_equals($refundNo, trim((string) $data['refundRequestNum']))) {
            throw new PaymentUncertainException('哆啦宝退款响应幂等号不一致', 40200, ['refund_no' => $refundNo]);
        }
        $responseAmount = $this->yuanToCents($data['refundAmount'] ?? null, '哆啦宝退款响应金额');
        if ($responseAmount !== $refundAmount) {
            throw new PaymentUncertainException('哆啦宝退款响应金额不一致', 40200, [
                'refund_no' => $refundNo,
                'expected_amount' => $refundAmount,
                'response_amount' => $responseAmount,
            ]);
        }
        $channelRefundNo = trim((string) ($data['bankRequestNum'] ?? ''));
        if ($channelRefundNo === '') {
            throw new PaymentUncertainException('哆啦宝退款已受理但缺少渠道退款号', 40200, [
                'refund_no' => $refundNo,
            ]);
        }

        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $responseAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => 'ACCEPTED',
            'message' => '哆啦宝退款申请已受理，资金结果待确认',
        ];
    }

    /**
     * 校验并解析支付通知。
     *
     * 使用原始请求体及 timestamp/token 验签，并与本地支付单核对通道、金额和已保存的
     * 渠道流水。未知状态保守映射为处理中，不据此推进失败状态。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $body = $request->rawBody();
        if (!$this->client()->verifyNotify(
            $body,
            trim((string) $request->header('timestamp', '')),
            trim((string) $request->header('token', ''))
        )) {
            throw new PaymentException('哆啦宝回调验签失败', 40200);
        }
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('哆啦宝回调不是合法 JSON', 40200);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new PaymentException('哆啦宝回调结构无效', 40200);
        }

        $this->assertNotifyMerchant($payload);
        $payNo = $this->requiredPayloadText($payload, 'requestNum', '哆啦宝回调缺少 requestNum');
        $paidAmount = $this->yuanToCents($payload['orderAmount'] ?? null, '哆啦宝回调金额');
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no',
        ]);
        if ($payOrder === null) {
            throw new PaymentException('哆啦宝回调未匹配到本地支付单', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('哆啦宝回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }
        if ((int) $payOrder->pay_amount !== $paidAmount) {
            throw new PaymentException('哆啦宝回调金额与支付单不一致', 40200, [
                'pay_no' => $payNo,
                'expected_amount' => (int) $payOrder->pay_amount,
                'notify_amount' => $paidAmount,
            ]);
        }

        $channelOrderNo = trim((string) ($payload['orderNum'] ?? ''));
        $channelTradeNo = trim((string) ($payload['bankRequestNum'] ?? ''));
        $statusText = strtoupper($this->requiredPayloadText($payload, 'status', '哆啦宝回调缺少 status'));
        $status = $this->notifyStatus($statusText);
        if ($status === PaymentPluginStatusConstant::SUCCESS
            && ($channelOrderNo === '' || $channelTradeNo === '')) {
            throw new PaymentException('哆啦宝成功回调缺少渠道订单号或支付流水号', 40200, ['pay_no' => $payNo]);
        }
        $storedOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedOrderNo !== '' && $channelOrderNo !== '' && !hash_equals($storedOrderNo, $channelOrderNo)) {
            throw new PaymentException('哆啦宝回调渠道订单号与支付单不一致', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && $channelTradeNo !== '' && !hash_equals($storedTradeNo, $channelTradeNo)) {
            throw new PaymentException('哆啦宝回调支付流水号与支付单不一致', 40200, ['pay_no' => $payNo]);
        }

        $unknown = !in_array($statusText, $this->knownNotifyStatuses(), true);
        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $paidAmount : null,
            'message' => $unknown ? '哆啦宝返回未识别状态，保守保持处理中' : '哆啦宝支付状态通知',
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $statusText,
            'channel_error_code' => $unknown ? 'DUOLABAO_STATUS_UNKNOWN' : '',
            'channel_error_msg' => $unknown ? '未识别的 rainbow_legacy 支付状态' : '',
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
        return 'error';
    }

    /**
     * 发起二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePay(array $order): array
    {
        $payNo = $this->requiredOrderText($order, 'pay_no', '哆啦宝下单缺少支付单号');
        $amount = $this->positiveAmount($order);
        try {
            $data = $this->client()->post('/api/generateQRCodeUrl', [
                'version' => 'V4.0',
                'agentNum' => $this->configText('agent_num'),
                'customerNum' => $this->configText('customer_num'),
                'shopNum' => $this->configText('shop_num'),
                'requestNum' => $payNo,
                'orderAmount' => FormatHelper::amount($amount),
                'subOrderType' => 'NORMAL',
                'orderType' => 'SALES',
                'timeExpire' => date('Y-m-d H:i:s', time() + 7200),
                'businessType' => self::PRODUCT_QRCODE_TRAD,
                'payModel' => 'ONCE',
                'source' => 'API',
                'callbackUrl' => (string) ($order['callback_url'] ?? ''),
                'completeUrl' => (string) ($order['return_url'] ?? ''),
                'clientIp' => (string) ($order['client_ip'] ?? ''),
            ]);
        } catch (DuolabaoSdkException $e) {
            $this->throwSdkException('哆啦宝聚合二维码下单', $e);
        }
        $this->assertResponseRequestNum($data, $payNo, '下单');
        $qrcode = trim((string) ($data['url'] ?? ''));
        if ($qrcode === '') {
            throw new PaymentUncertainException('哆啦宝二维码下单响应缺少 url', 40200, ['pay_no' => $payNo]);
        }

        return $this->paymentResult($order, self::PRODUCT_QRCODE_TRAD, 'qrcode', [
            'qrcode' => $qrcode,
        ], $data, 'generateQRCodeUrl');
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
            throw new PaymentDefinitiveException('哆啦宝支付宝 JSAPI 缺少 buyer_id', 40200);
        }
        $data = $this->requestJsapi($order, 'ALIPAY', $buyerId, null);
        $bankRequest = $this->bankRequest($data, (string) $order['pay_no']);
        $tradeNo = trim((string) ($bankRequest['TRADENO'] ?? ''));
        if ($tradeNo === '') {
            throw new PaymentUncertainException('哆啦宝支付宝下单响应缺少 TRADENO', 40200, [
                'pay_no' => (string) $order['pay_no'],
            ]);
        }

        return $this->paymentResult($order, self::PRODUCT_ALIPAY_JSAPI, 'jsapi', [
            'tradeNO' => $tradeNo,
        ], $data, 'createPayWithCheck');
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
        $scope = $this->wxScope($order, $mini);
        $payment = $this->paymentPayload($order);
        $authCode = trim((string) ($payment[$scope['identity_field']] ?? ''));
        if ($authCode === '') {
            throw new PaymentDefinitiveException(
                '哆啦宝微信支付缺少 ' . $scope['identity_field'],
                40200
            );
        }
        $data = $this->requestJsapi($order, $mini ? 'WX_XCX' : 'WX', $authCode, $scope);
        $bankRequest = $this->bankRequest($data, (string) $order['pay_no']);
        $params = $this->wechatPayParams($bankRequest, $mini, (string) $order['pay_no']);
        if ($mini) {
            return $this->paymentResult($order, self::PRODUCT_WX_JSAPI, 'page', [
                '_page' => 'wechatMini',
                'request_payment' => $params,
                'app_id' => $scope['identity_app_id'],
                'description' => '哆啦宝微信小程序支付参数已生成，请由小程序调用 wx.requestPayment。',
            ], $data, 'createPayWithCheck');
        }

        return $this->paymentResult(
            $order,
            self::PRODUCT_WX_JSAPI,
            'jsapi',
            $params,
            $data,
            'createPayWithCheck'
        );
    }

    /**
     * 发起 JSAPI 上游请求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $bankType 哆啦宝支付体系编码
     * @param string $authCode 渠道用户身份
     * @param array<string, string>|null $scope 微信应用作用域；支付宝支付传 null
     * @return array<string, mixed> 哆啦宝下单响应
     */
    private function requestJsapi(array $order, string $bankType, string $authCode, ?array $scope): array
    {
        $payNo = $this->requiredOrderText($order, 'pay_no', '哆啦宝下单缺少支付单号');
        $payload = [
            'version' => 'V4.0',
            'agentNum' => $this->configText('agent_num'),
            'customerNum' => $this->configText('customer_num'),
            'shopNum' => $this->configText('shop_num'),
            'bankType' => $bankType,
            'paySource' => $bankType,
            'authCode' => $authCode,
            'requestNum' => $payNo,
            'orderAmount' => FormatHelper::amount($this->positiveAmount($order)),
            'subOrderType' => 'NORMAL',
            'orderType' => 'SALES',
            'payType' => 'ACTIVE',
            'businessType' => self::PRODUCT_QRCODE_TRAD,
            'payModel' => 'ONCE',
            'source' => 'API',
            'timeExpire' => date('Y-m-d H:i:s', time() + 7200),
            'callbackUrl' => (string) ($order['callback_url'] ?? ''),
            'clientIp' => (string) ($order['client_ip'] ?? ''),
        ];
        if ($scope !== null) {
            if ($scope['app_id'] !== '') {
                $payload['appId'] = $scope['app_id'];
            }
            if ($scope['sub_app_id'] !== '') {
                $payload['subAppId'] = $scope['sub_app_id'];
            }
        }

        try {
            $data = $this->client()->post('/api/createPayWithCheck', $payload);
        } catch (DuolabaoSdkException $e) {
            $this->throwSdkException('哆啦宝 JSAPI 下单', $e);
        }
        $this->assertResponseRequestNum($data, $payNo, 'JSAPI 下单');

        return $data;
    }

    /**
     * 读取 JSAPI 下单响应中的银行调起参数。
     *
     * @param array<string, mixed> $data 哆啦宝下单响应
     * @param string $payNo 平台支付单号
     * @return array<string, mixed> 银行调起参数
     */
    private function bankRequest(array $data, string $payNo): array
    {
        $bankRequest = $data['bankRequest'] ?? null;
        if (!is_array($bankRequest) || array_is_list($bankRequest)) {
            throw new PaymentUncertainException('哆啦宝 JSAPI 下单响应缺少 bankRequest', 40200, [
                'pay_no' => $payNo,
            ]);
        }

        return $bankRequest;
    }

    /**
     * 构建微信前端调起参数。
     *
     * @param array<string, mixed> $bankRequest 哆啦宝银行调起参数
     * @param bool $mini 是否为微信小程序支付
     * @param string $payNo 平台支付单号
     * @return array<string, string> 微信前端调起参数
     */
    private function wechatPayParams(array $bankRequest, bool $mini, string $payNo): array
    {
        $signTypeField = $mini ? 'SIGNTYPE' : 'SIBGTYPE';
        $mapping = [
            'appId' => 'APPID',
            'timeStamp' => 'TIMESTAMP',
            'nonceStr' => 'NONCESTR',
            'package' => 'PACKAGE',
            'signType' => $signTypeField,
            'paySign' => 'PAYSIGN',
        ];
        $params = [];
        foreach ($mapping as $target => $source) {
            $value = trim((string) ($bankRequest[$source] ?? ''));
            if ($value === '') {
                throw new PaymentUncertainException('哆啦宝微信下单响应缺少 ' . $source, 40200, [
                    'pay_no' => $payNo,
                ]);
            }
            $params[$target] = $value;
        }

        return $params;
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 哆啦宝产品编码
     * @param string $page 平台承接页类型
     * @param array<string, mixed> $payParams 前端调起参数
     * @param array<string, mixed> $data 哆啦宝下单响应
     * @param string $action 哆啦宝接口动作
     * @return array<string, mixed> 标准支付结果
     */
    private function paymentResult(
        array $order,
        string $product,
        string $page,
        array $payParams,
        array $data,
        string $action
    ): array {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => (string) ($order['pay_type_code'] ?? ''),
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => trim((string) ($data['orderNum'] ?? '')),
            'chan_trade_no' => trim((string) ($data['bankRequestNum'] ?? '')),
        ]);
    }

    /**
     * 微信主商户 appId 与子商户 subAppId 分开建模，不复制同一个值。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否为微信小程序支付
     * @return array<string, string> 下单和身份授权使用的微信应用作用域
     */
    private function wxScope(array $order, bool $mini): array
    {
        $platformAppId = $this->configText('wx_platform_app_id');
        $childAppId = $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id');
        $childSecret = $this->configText($mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret');
        $identityAppId = $childAppId !== '' ? $childAppId : $platformAppId;
        $identitySecret = $childAppId !== '' ? $childSecret : $this->configText('wx_platform_app_secret');
        if ($identityAppId === '') {
            throw new PaymentException($mini ? '哆啦宝微信小程序缺少 AppID' : '哆啦宝微信公众号缺少 AppID', 40200);
        }
        $payment = $this->paymentPayload($order);
        $requestedAppId = trim((string) ($payment['sub_appid'] ?? ''));
        if ($requestedAppId !== '' && !hash_equals($identityAppId, $requestedAppId)) {
            throw new PaymentException('哆啦宝微信身份 AppID 与通道配置不一致', 40200);
        }

        return [
            'app_id' => $platformAppId !== '' ? $platformAppId : $identityAppId,
            'sub_app_id' => $platformAppId !== '' && $childAppId !== '' ? $childAppId : '',
            'identity_app_id' => $identityAppId,
            'identity_app_secret' => $identitySecret,
            'identity_field' => $mini
                ? 'mini_openid'
                : ($platformAppId !== '' && $childAppId !== '' ? 'sub_openid' : 'openid'),
        ];
    }

    /**
     * 读取订单选中的支付产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return string 已启用且适配当前环境的产品编码
     */
    private function selectedProduct(array $order): string
    {
        $payType = trim((string) ($order['pay_type_code'] ?? ''));
        if (!in_array($payType, ['alipay', 'wxpay'], true)) {
            throw new PaymentDefinitiveException('哆啦宝不支持当前支付方式', 40200);
        }
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '' && !in_array($method, ['qrcode', 'jsapi', 'mini'], true)) {
            throw new PaymentProductUnavailableException('哆啦宝不支持指定的支付产品', 40200);
        }
        if ($method === 'qrcode') {
            return $this->requireProduct(self::PRODUCT_QRCODE_TRAD);
        }
        if ($method === 'mini' || $this->isMiniOrder($order)) {
            if ($payType !== 'wxpay') {
                throw new PaymentDefinitiveException('哆啦宝小程序产品仅支持微信支付', 40200);
            }
            return $this->requireProduct(self::PRODUCT_WX_JSAPI);
        }

        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));
        $jsapiProduct = $payType === 'alipay' ? self::PRODUCT_ALIPAY_JSAPI : self::PRODUCT_WX_JSAPI;
        $jsapiEnvironment = ($payType === 'alipay' && $env === 'alipay')
            || ($payType === 'wxpay' && $env === 'wechat');
        if ($method === 'jsapi') {
            if (!$jsapiEnvironment) {
                throw new PaymentDefinitiveException('哆啦宝 JSAPI 与当前支付环境不匹配', 40200);
            }
            return $this->requireProduct($jsapiProduct);
        }
        if ($jsapiEnvironment && $this->productEnabled($jsapiProduct)) {
            return $jsapiProduct;
        }
        if ($this->productEnabled(self::PRODUCT_QRCODE_TRAD)) {
            return self::PRODUCT_QRCODE_TRAD;
        }

        throw new PaymentDefinitiveException('哆啦宝当前环境没有已开通的确定支付产品', 40200);
    }

    private function requireProduct(string $product): string
    {
        if (!$this->productEnabled($product)) {
            throw new PaymentProductUnavailableException('哆啦宝支付产品未开通：' . $product, 40200);
        }

        return $product;
    }

    private function productEnabled(string $product): bool
    {
        return in_array($product, $this->enabledProducts(), true);
    }

    /**
     * 读取已开通支付产品。
     *
     * @return array<int, string>
     */
    private function enabledProducts(): array
    {
        $products = $this->getConfig('enabled_products', []);
        if (!is_array($products)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $product): string => trim((string) $product),
            $products
        )));
    }

    /**
     * 判断订单是否为小程序支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return bool 是否为微信小程序支付
     */
    private function isMiniOrder(array $order): bool
    {
        if ((string) ($order['pay_type_code'] ?? '') !== 'wxpay') {
            return false;
        }
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            return $method === 'mini';
        }

        return trim((string) ($payment['mini_openid'] ?? '')) !== ''
            || trim((string) ($payment['wx_login_code'] ?? '')) !== ''
            || trim((string) ($payment['mini_code'] ?? '')) !== ''
            || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 读取标准支付载体参数。
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
     * 校验响应请求号。
     *
     * @param array<string, mixed> $data 哆啦宝响应
     * @param string $payNo 预期平台支付单号
     * @param string $action 业务动作说明
     * @return void
     */
    private function assertResponseRequestNum(array $data, string $payNo, string $action): void
    {
        if (!isset($data['requestNum'])) {
            return;
        }
        $responsePayNo = trim((string) $data['requestNum']);
        if ($responsePayNo === '' || !hash_equals($payNo, $responsePayNo)) {
            throw new PaymentUncertainException('哆啦宝' . $action . '响应 requestNum 不一致', 40200, [
                'pay_no' => $payNo,
            ]);
        }
    }

    /**
     * 校验通知商户身份。
     *
     * @param array<string, mixed> $payload 支付通知载荷
     * @return void
     */
    private function assertNotifyMerchant(array $payload): void
    {
        $customerNum = $this->requiredPayloadText($payload, 'customerNum', '哆啦宝回调缺少 customerNum');
        $shopNum = $this->requiredPayloadText($payload, 'shopNum', '哆啦宝回调缺少 shopNum');
        if (!hash_equals($this->configText('customer_num'), $customerNum)
            || !hash_equals($this->configText('shop_num'), $shopNum)) {
            throw new PaymentException('哆啦宝回调商户或门店不一致', 40200);
        }
    }

    /**
     * 将哆啦宝通知状态映射为平台支付状态。
     *
     * 未识别状态按处理中返回，避免把新增或临时状态误判为支付失败。
     *
     * @param string $status 哆啦宝通知状态
     * @return string 平台支付状态
     */
    private function notifyStatus(string $status): string
    {
        if ($status === 'SUCCESS') {
            return PaymentPluginStatusConstant::SUCCESS;
        }
        if (in_array($status, ['FAIL', 'FAILED', 'CANCEL', 'CANCELED', 'CLOSED'], true)) {
            return PaymentPluginStatusConstant::FAILED;
        }

        return PaymentPluginStatusConstant::PENDING;
    }

    /**
     * 获取已知通知状态。
     *
     * @return array<int, string>
     */
    private function knownNotifyStatuses(): array
    {
        return ['SUCCESS', 'INIT', 'PROCESSING', 'PAYING', 'FAIL', 'FAILED', 'CANCEL', 'CANCELED', 'CLOSED'];
    }

    /**
     * 读取并校验正整数金额。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return int 支付金额，单位分
     */
    private function positiveAmount(array $order): int
    {
        $amount = (int) ($order['amount'] ?? 0);
        if ($amount <= 0) {
            throw new PaymentDefinitiveException('哆啦宝支付金额无效', 40200);
        }

        return $amount;
    }

    /**
     * 读取必填订单字段。
     *
     * @param array<string, mixed> $values 标准插件订单参数
     * @param string $field 字段名
     * @param string $message 校验失败消息
     * @return string 字段值
     */
    private function requiredOrderText(array $values, string $field, string $message): string
    {
        $value = trim((string) ($values[$field] ?? ''));
        if ($value === '' || strlen($value) > 64) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return $value;
    }

    /**
     * 读取必填通知字段。
     *
     * @param array<string, mixed> $values 支付通知载荷
     * @param string $field 字段名
     * @param string $message 校验失败消息
     * @return string 字段值
     */
    private function requiredPayloadText(array $values, string $field, string $message): string
    {
        $value = trim((string) ($values[$field] ?? ''));
        if ($value === '' || strlen($value) > 128) {
            throw new PaymentException($message, 40200);
        }

        return $value;
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
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    /**
     * 按 SDK 异常确定性转换支付异常。
     *
     * 已发出且无法确认结果的请求映射为不确定异常，其余明确失败映射为确定失败异常。
     *
     * @param string $action 业务动作说明
     * @param DuolabaoSdkException $e SDK 异常
     * @return never
     */
    private function throwSdkException(string $action, DuolabaoSdkException $e): never
    {
        $context = $e->channelErrorCode() === '' ? [] : ['channel_error_code' => $e->channelErrorCode()];
        if ($e->isUncertain()) {
            throw new PaymentUncertainException($action . '结果不确定：' . $e->getMessage(), 40200, $context);
        }

        throw new PaymentDefinitiveException($action . '失败：' . $e->getMessage(), 40200, $context);
    }

    /**
     * 获取当前通道的哆啦宝客户端。
     *
     * @return DuolabaoClient
     */
    private function client(): DuolabaoClient
    {
        if ($this->client === null) {
            $this->client = new DuolabaoClient([
                'access_key' => $this->configText('access_key'),
                'secret_key' => $this->configText('secret_key'),
            ]);
        }

        return $this->client;
    }

    private function configText(string $key, string $default = ''): string
    {
        return trim((string) $this->getConfig($key, $default));
    }
}
