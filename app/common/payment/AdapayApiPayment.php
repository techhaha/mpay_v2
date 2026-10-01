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
use app\common\sdk\adapay\AdapayClient;
use app\common\sdk\adapay\AdapaySdkException;
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
 * AdaPay 支付 API 插件。
 *
 * 对接支付宝生活号、微信公众号、支付宝正扫、微信小程序和银联云闪付正扫五类产品。
 * 插件负责严格校验应用身份、Payment 对象引用和通知业务字段，并输出 MPAY 标准支付结果。
 */
class AdapayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface,
    RefundQueryInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_PUB = 'alipay_pub';
    private const PRODUCT_WX_PUB = 'wx_pub';
    private const PRODUCT_ALIPAY_QR = 'alipay_qr';
    private const PRODUCT_WX_LITE = 'wx_lite';
    private const PRODUCT_UNION_QR = 'union_qr';

    private ?AdapayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'adapay_api',
        'name' => 'AdaPay支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.adapay.tech/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 创建 AdaPay 支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付单仓库；测试可注入替身
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
            $this->inputField('app_id', 'AdaPay 应用 AppID', true),
            ['type' => 'password', 'field' => 'api_key', 'title' => '当前模式 API Key', 'value' => '', 'validate' => [['required' => true, 'message' => 'API Key不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户 RSA 私钥', 'value' => '', 'props' => ['rows' => 6], 'validate' => [['required' => true, 'message' => '商户 RSA 私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => 'AdaPay 平台公钥', 'value' => '', 'props' => ['rows' => 5], 'validate' => [['required' => true, 'message' => 'AdaPay 平台公钥不能为空']]],
            $this->inputField('wechat_mp_app_id', '微信公众号 AppID'),
            ['type' => 'password', 'field' => 'wechat_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            $this->inputField('wechat_mini_app_id', '微信小程序 AppID'),
            ['type' => 'password', 'field' => 'wechat_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            $this->inputField('wechat_mini_launch_path', '微信小程序支付承接路径'),
            $this->inputField('alipay_oauth_app_id', '支付宝生活号/应用 AppID'),
            ['type' => 'textarea', 'field' => 'alipay_oauth_private_key', 'title' => '支付宝 OAuth 应用私钥', 'value' => '', 'props' => ['rows' => 5]],
            ['type' => 'textarea', 'field' => 'alipay_oauth_public_key', 'title' => '支付宝公钥', 'value' => '', 'props' => ['rows' => 4]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_PUB => '支付宝生活号支付',
                self::PRODUCT_WX_PUB => '微信公众号支付',
                self::PRODUCT_ALIPAY_QR => '支付宝正扫',
                self::PRODUCT_WX_LITE => '微信小程序支付',
                self::PRODUCT_UNION_QR => '银联云闪付正扫',
            ]),
        ];
    }

    /**
     * 检查支付所需的用户身份。
     *
     * 支付宝、公众号和小程序身份不能跨应用或跨终端复用；已有同一作用域身份时返回 null。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @return array<string, mixed>|null 身份需求；无需授权时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));

        if ($this->firstText($payment['auth_code'] ?? '') !== '') {
            return null;
        }
        if ($payType === 'alipay' && $env === 'alipay' && in_array($method, ['', 'jsapi'], true)) {
            $this->ensureProduct(self::PRODUCT_ALIPAY_PUB);
            $this->assertAlipayAppScope($payment);
            if ($this->firstText($payment['buyer_id'] ?? '') !== '') {
                return null;
            }
            $this->requireConfig([
                'alipay_oauth_app_id',
                'alipay_oauth_private_key',
                'alipay_oauth_public_key',
            ], 'AdaPay支付宝生活号身份流程配置不完整');

            return [
                'provider' => 'alipay',
                'product' => 'jsapi',
                'channel_product' => self::PRODUCT_ALIPAY_PUB,
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
                    'sandbox' => false,
                ],
                'message' => 'AdaPay支付宝生活号支付需要当前支付宝应用的 buyer_id',
            ];
        }

        if ($payType !== 'wxpay') {
            return null;
        }
        $mini = $this->isMiniIntent($payment);
        if (!$mini && ($env !== 'wechat' || !in_array($method, ['', 'jsapi'], true))) {
            return null;
        }

        $product = $mini ? self::PRODUCT_WX_LITE : self::PRODUCT_WX_PUB;
        $this->ensureProduct($product);
        $appId = $this->wxAppId($payment, $mini);
        $openid = $mini
            ? $this->firstText($payment['mini_openid'] ?? '')
            : $this->wechatMpOpenId($payment);
        if ($openid !== '') {
            return null;
        }

        $secretField = $mini ? 'wechat_mini_app_secret' : 'wechat_mp_app_secret';
        if ($appId === '' || $this->configText($secretField) === '') {
            throw new PaymentDefinitiveException($mini
                ? 'AdaPay微信小程序缺少身份，且未配置小程序 AppID/AppSecret'
                : 'AdaPay微信公众号缺少身份，且未配置公众号 AppID/AppSecret', 40200);
        }

        return [
            'provider' => 'wxpay',
            'product' => $mini ? 'mini' : 'mp',
            'channel_product' => $product,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'openid',
            'identity_aliases' => $mini ? [] : ['sub_openid'],
            'app_id' => $appId,
            '_app_secret' => $this->configText($secretField),
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wechat_mini_launch_path') : '',
            'mini_launch_type' => $mini && $env === 'wechat' ? 'url_link' : 'url_scheme',
            'env_version' => 'release',
            'message' => $mini
                ? 'AdaPay微信小程序支付需要当前小程序作用域的 mini_openid'
                : 'AdaPay微信公众号支付需要当前公众号作用域的 openid',
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['auth_code'] ?? '') !== '') {
            throw new PaymentDefinitiveException('AdaPay当前五类产品不支持付款码被扫', 40200);
        }

        $mini = $payType === 'wxpay' && $this->isMiniIntent($payment);
        $wxProduct = $mini ? self::PRODUCT_WX_LITE : self::PRODUCT_WX_PUB;
        $jsapiHandler = $mini ? 'mini' : 'jsapi';
        if ($mini) {
            $this->ensureProduct(self::PRODUCT_WX_LITE);
            $order['_env'] = 'wechat';
        }

        return $this->executeDirectPaymentProduct($order, [
            $jsapiHandler => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_PUB,
                    'wxpay' => $wxProduct,
                ],
                'handler' => fn (): array => $this->jsapiPay($order, $wxProduct),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_QR,
                    'bank' => self::PRODUCT_UNION_QR,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ], 'AdaPay');
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准查单参数
     * @return array<string, mixed> 标准查单结果
     */
    public function query(array $order): array
    {
        $paymentId = $this->paymentId($order, true);
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentUncertainException('AdaPay查单缺少本地支付单号', 40200);
        }
        $product = $this->orderProduct($order);
        $this->positiveCents($order['amount'] ?? null, 'AdaPay本地订单金额无效');
        try {
            $data = $this->client()->queryPayment($paymentId);
        } catch (AdapaySdkException $e) {
            throw new PaymentUncertainException('AdaPay查单失败：' . $e->getMessage(), 40200, $this->sdkError($e));
        }

        $this->assertPaymentReference($data, $paymentId, $payNo, $product, $order['amount'] ?? null, true);
        $channelStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $status = match ($channelStatus) {
            'succeeded' => PaymentPluginStatusConstant::SUCCESS,
            'failed' => PaymentPluginStatusConstant::FAILED,
            'closed' => PaymentPluginStatusConstant::CLOSED,
            'pending' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };
        $responseTradeNo = trim((string) ($data['out_trans_id'] ?? ''));
        $storedTradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($responseTradeNo !== '' && $storedTradeNo !== '' && !hash_equals($storedTradeNo, $responseTradeNo)) {
            throw new PaymentUncertainException('AdaPay查单渠道交易号与本地记录不一致', 40200);
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->yuanToCents($data['pay_amt'] ?? null, 'AdaPay查单金额', PaymentUncertainException::class)
                : null,
            'chan_order_no' => $paymentId,
            'chan_trade_no' => $responseTradeNo !== '' ? $responseTradeNo : $storedTradeNo,
            'channel_status' => $channelStatus,
            'channel_error_code' => trim((string) ($data['error_code'] ?? '')),
            'channel_error_msg' => trim((string) ($data['error_msg'] ?? '')),
            'message' => $this->responseMessage($data, $channelStatus),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->adapayTime($data['end_time'] ?? '')
                : null,
        ];
    }

    /**
     * 拒绝未实现协议闭环的关单请求。
     *
     * 当前接入范围没有可验证的 AdaPay 关单接口，不能以查单或退款结果代替关单事实。
     *
     * @param array<string, mixed> $order 标准关单参数
     * @return array<string, mixed> 不会返回，始终抛出不支持异常
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('AdaPay插件暂不支持关单', 40200);
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $paymentId = $this->paymentId($order, false);
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentDefinitiveException('AdaPay退款缺少本地支付单号', 40200);
        }
        $refundNo = trim((string) ($order['refund_no'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $refundNo) !== 1) {
            throw new PaymentDefinitiveException('AdaPay退款请求号必须为1-64位字母、数字或下划线', 40200);
        }
        $refundAmount = $this->positiveCents($order['refund_amount'] ?? null, 'AdaPay退款金额必须是正整数分');
        $payAmount = $this->positiveCents($order['amount'] ?? null, 'AdaPay原支付金额必须是正整数分');
        if ($refundAmount > $payAmount) {
            throw new PaymentDefinitiveException('AdaPay退款金额不能大于原支付金额', 40200);
        }

        $payload = [
            'refund_order_no' => $refundNo,
            'refund_amt' => FormatHelper::amount($refundAmount),
        ];
        $reason = trim((string) ($order['refund_reason'] ?? ''));
        if ($reason !== '') {
            $payload['reason'] = mb_strcut($reason, 0, 512, 'UTF-8');
        }
        try {
            $data = $this->client()->refund($paymentId, $payload);
        } catch (AdapaySdkException $e) {
            $this->throwMutationFailure($e, 'AdaPay退款失败');
        }

        $channelRefundNo = trim((string) ($data['id'] ?? ''));
        if ($channelRefundNo === '') {
            throw new PaymentUncertainException('AdaPay退款响应缺少退款对象ID', 40200);
        }
        if (!hash_equals($paymentId, trim((string) ($data['payment_id'] ?? '')))) {
            throw new PaymentUncertainException('AdaPay退款响应原支付对象ID不一致', 40200);
        }
        $responseAmount = $this->yuanToCents($data['refund_amt'] ?? null, 'AdaPay退款响应金额', PaymentUncertainException::class);
        if ($responseAmount !== $refundAmount) {
            throw new PaymentUncertainException('AdaPay退款响应金额不一致', 40200);
        }
        $responseRefundNo = trim((string) ($data['refund_order_no'] ?? ''));
        if ($responseRefundNo === '' || !hash_equals($refundNo, $responseRefundNo)) {
            throw new PaymentUncertainException('AdaPay退款响应退款请求号不一致', 40200);
        }

        $channelStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $transState = strtoupper(trim((string) ($data['trans_state'] ?? '')));
        if ($channelStatus === 'failed' || $transState === 'F') {
            throw new PaymentDefinitiveException(
                'AdaPay明确拒绝退款：' . $this->responseMessage($data, $transState ?: $channelStatus),
                40200,
                ['channel_error_code' => trim((string) ($data['error_code'] ?? ''))]
            );
        }
        $status = $channelStatus === 'succeeded' && $transState === 'S'
            ? PaymentPluginStatusConstant::SUCCESS
            : (in_array($channelStatus, ['pending', 'succeeded'], true) || in_array($transState, ['I', 'P', 'REFUNDED'], true)
                ? PaymentPluginStatusConstant::PENDING
                : PaymentPluginStatusConstant::UNKNOWN);

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'channel_status' => trim($channelStatus . ($transState !== '' ? '/' . $transState : '')),
            'message' => match ($status) {
                PaymentPluginStatusConstant::SUCCESS => 'AdaPay退款已确认成功',
                PaymentPluginStatusConstant::PENDING => 'AdaPay退款已受理，等待终态确认',
                default => 'AdaPay退款状态无法确认',
            },
        ];
    }

    /**
     * 查询退款订单。
     *
     * @param array<string, mixed> $refund 标准退款查询参数
     * @return array<string, mixed> 标准退款状态结果
     */
    public function queryRefund(array $refund): array
    {
        $paymentId = $this->paymentId($refund, true);
        $payNo = trim((string) ($refund['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentUncertainException('AdaPay退款查询缺少本地支付单号', 40200);
        }
        $refundNo = trim((string) ($refund['refund_no'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $refundNo) !== 1) {
            throw new PaymentUncertainException('AdaPay退款查询缺少有效退款请求号', 40200);
        }
        $refundAmountValue = $refund['refund_amount'] ?? null;
        if ((!is_int($refundAmountValue) && !is_string($refundAmountValue))
            || preg_match('/^[1-9]\d*$/D', trim((string) $refundAmountValue)) !== 1) {
            throw new PaymentUncertainException('AdaPay退款查询金额必须是正整数分', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        $refundAmount = (int) $refundAmountValue;
        $refundId = trim((string) ($refund['chan_refund_no'] ?? ''));
        if ($refundId === '' || strlen($refundId) > 64) {
            throw new PaymentUncertainException('AdaPay退款查询缺少有效退款对象ID', 40200, [
                'refund_no' => $refundNo,
            ]);
        }

        try {
            $data = $this->client()->queryRefund($refundId);
        } catch (AdapaySdkException $e) {
            throw new PaymentUncertainException(
                'AdaPay退款查询失败：' . $e->getMessage(),
                40200,
                $this->sdkError($e)
            );
        }

        $queryStatus = strtolower(trim((string) ($data['status'] ?? '')));
        if ($queryStatus !== 'succeeded') {
            throw new PaymentUncertainException('AdaPay退款查询未返回可确认结果：' . $this->responseMessage($data, $queryStatus), 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        $refunds = $data['refunds'] ?? null;
        if (!is_array($refunds) || !array_is_list($refunds)) {
            throw new PaymentUncertainException('AdaPay退款查询响应缺少退款对象列表', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        $matches = array_values(array_filter(
            $refunds,
            static fn (mixed $item): bool => is_array($item)
                && hash_equals($refundId, trim((string) ($item['refund_id'] ?? '')))
        ));
        if (count($matches) !== 1) {
            throw new PaymentUncertainException('AdaPay退款查询未唯一匹配退款对象ID', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        $item = $matches[0];
        if (!hash_equals($paymentId, trim((string) ($item['payment_id'] ?? '')))) {
            throw new PaymentUncertainException('AdaPay退款查询原支付对象ID不一致', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        if (!hash_equals($refundNo, trim((string) ($item['refund_order_no'] ?? '')))) {
            throw new PaymentUncertainException('AdaPay退款查询退款请求号不一致', 40200, [
                'refund_no' => $refundNo,
            ]);
        }
        $responseAmount = $this->yuanToCents(
            $item['refund_amt'] ?? null,
            'AdaPay退款查询金额',
            PaymentUncertainException::class
        );
        if ($responseAmount !== $refundAmount) {
            throw new PaymentUncertainException('AdaPay退款查询金额不一致', 40200, [
                'refund_no' => $refundNo,
            ]);
        }

        $channelStatus = strtoupper(trim((string) ($item['trans_status'] ?? '')));
        $status = match ($channelStatus) {
            'S' => PaymentPluginStatusConstant::SUCCESS,
            'F' => PaymentPluginStatusConstant::FAILED,
            'I', 'P' => PaymentPluginStatusConstant::PENDING,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $refundId,
            'channel_status' => $channelStatus,
            'message' => match ($status) {
                PaymentPluginStatusConstant::SUCCESS => 'AdaPay退款查询确认成功',
                PaymentPluginStatusConstant::FAILED => 'AdaPay退款查询确认失败',
                PaymentPluginStatusConstant::PENDING => 'AdaPay退款仍在处理中',
                default => 'AdaPay退款查询状态无法识别',
            },
        ];
    }

    /**
     * 校验并解析支付通知。
     *
     * 验签后继续核对事件类型、应用 ID、Payment 对象、支付产品、金额和渠道交易号。
     *
     * @param Request $request AdaPay 通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $sign = (string) ($request->post('sign') ?? '');
        $dataText = (string) ($request->post('data') ?? '');
        try {
            if (!$this->client()->verifyNotify($sign, $dataText)) {
                throw new PaymentException('AdaPay回调验签失败', 40200);
            }
        } catch (AdapaySdkException $e) {
            throw new PaymentException('AdaPay回调验签配置无效：' . $e->getMessage(), 40200);
        }

        try {
            $payload = json_decode($dataText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException('AdaPay回调data不是合法JSON', 40200);
        }
        if (!is_array($payload)) {
            throw new PaymentException('AdaPay回调data结构无效', 40200);
        }
        if (!hash_equals('payment.succeeded', trim((string) ($request->post('type') ?? '')))
            || !hash_equals('payment', trim((string) ($request->post('object') ?? '')))) {
            throw new PaymentException('AdaPay回调事件类型不是明确支付成功', 40200);
        }
        $eventAppId = trim((string) ($request->post('app_id') ?? ''));
        if ($eventAppId === '' || !hash_equals($this->configText('app_id'), $eventAppId)) {
            throw new PaymentException('AdaPay回调应用ID不一致', 40200);
        }
        $payloadAppId = trim((string) ($payload['app_id'] ?? ''));
        if ($payloadAppId !== '' && !hash_equals($eventAppId, $payloadAppId)) {
            throw new PaymentException('AdaPay回调支付对象应用ID不一致', 40200);
        }
        if (!hash_equals('succeeded', strtolower(trim((string) ($payload['status'] ?? ''))))) {
            throw new PaymentException('AdaPay回调支付对象不是成功状态', 40200);
        }

        $payNo = trim((string) ($payload['order_no'] ?? ''));
        $paymentId = trim((string) ($payload['id'] ?? ''));
        $product = trim((string) ($payload['pay_channel'] ?? ''));
        if ($payNo === '' || $paymentId === '' || $product === '') {
            throw new PaymentException('AdaPay回调缺少订单号、支付对象ID或支付产品', 40200);
        }
        $paidAmount = $this->yuanToCents($payload['pay_amt'] ?? null, 'AdaPay回调金额');
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new PaymentException('AdaPay回调未匹配到本地支付单', 40200);
        }
        $this->assertNotifyOrder($payOrder, $paymentId, $product, $paidAmount, $payload);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'message' => 'AdaPay支付成功',
            'chan_order_no' => $paymentId,
            'chan_trade_no' => trim((string) ($payload['out_trans_id'] ?? '')),
            'channel_status' => 'succeeded',
            'paid_at' => $this->adapayTime($payload['end_time'] ?? ''),
        ];
    }

    /**
     * 返回渠道要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'Ok';
    }

    /**
     * 返回渠道要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'No';
    }

    /**
     * 发起二维码支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order): array
    {
        $payType = $this->payTypeCode($order);
        $product = match ($payType) {
            'alipay' => self::PRODUCT_ALIPAY_QR,
            'bank' => self::PRODUCT_UNION_QR,
            default => throw new PaymentDefinitiveException('AdaPay没有微信正扫产品', 40200),
        };
        $data = $this->createPayment($order, $product, []);
        $expend = $data['expend'] ?? null;
        $qrcode = is_array($expend) ? trim((string) ($expend['qrcode_url'] ?? '')) : '';
        if ($qrcode === '') {
            throw new PaymentUncertainException('AdaPay下单已返回支付对象，但缺少qrcode_url', 40200, [
                'payment_id' => (string) $data['id'],
                'pay_product' => $product,
            ]);
        }

        return $this->paymentResult($order, $product, 'qrcode', ['qrcode' => $qrcode], $data);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $wxProduct): array
    {
        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        if ($payType === 'alipay') {
            $product = self::PRODUCT_ALIPAY_PUB;
            $this->assertAlipayAppScope($payment);
            $buyerId = $this->firstText($payment['buyer_id'] ?? '');
            if ($buyerId === '') {
                throw new PaymentDefinitiveException('AdaPay支付宝生活号支付缺少buyer_id', 40200);
            }
            $expend = ['buyer_id' => $buyerId];
        } elseif ($payType === 'wxpay') {
            $mini = $this->isMiniIntent($payment);
            $product = $mini ? self::PRODUCT_WX_LITE : self::PRODUCT_WX_PUB;
            if ($product !== $wxProduct) {
                throw new PaymentDefinitiveException('AdaPay微信支付产品选择不一致', 40200);
            }
            $openid = $mini
                ? $this->firstText($payment['mini_openid'] ?? '')
                : $this->wechatMpOpenId($payment);
            if ($openid === '') {
                throw new PaymentDefinitiveException($mini
                    ? 'AdaPay微信小程序支付缺少mini_openid'
                    : 'AdaPay微信公众号支付缺少openid', 40200);
            }
            $expend = ['open_id' => $openid, 'is_raw' => '1'];
            $wxAppId = $this->wxAppId($payment, $mini);
            if ($wxAppId !== '') {
                $expend['wx_app_id'] = $wxAppId;
            }
        } else {
            throw new PaymentDefinitiveException('AdaPay当前支付方式不支持JSAPI', 40200);
        }

        $data = $this->createPayment($order, $product, $expend);
        $payInfo = $this->payInfo($data, $product);
        if ($product === self::PRODUCT_ALIPAY_PUB) {
            $tradeNo = trim((string) ($payInfo['tradeNO'] ?? ''));
            if ($tradeNo === '') {
                throw new PaymentUncertainException('AdaPay支付宝下单已创建支付对象，但pay_info缺少tradeNO', 40200, [
                    'payment_id' => (string) $data['id'],
                ]);
            }
            $page = 'jsapi';
            $params = ['tradeNO' => $tradeNo];
        } elseif ($product === self::PRODUCT_WX_LITE) {
            $page = 'page';
            $params = [
                '_page' => 'wechatMini',
                'request_payment' => $payInfo,
                'description' => mb_strcut((string) ($order['subject'] ?? ''), 0, 42, 'UTF-8'),
            ];
        } else {
            $page = 'jsapi';
            $params = $payInfo;
        }

        return $this->paymentResult($order, $product, $page, $params, $data);
    }

    /**
     * 创建上游支付订单。
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $expend
     * @return array<string, mixed>
     */
    private function createPayment(array $order, string $product, array $expend): array
    {
        $payload = $this->basePayload($order, $product) + ['pay_channel' => $product];
        if ($expend !== []) {
            $payload['expend'] = $expend;
        }
        try {
            $data = $this->client()->createPayment($payload);
        } catch (AdapaySdkException $e) {
            $this->throwMutationFailure($e, 'AdaPay下单失败');
        }

        $this->assertPaymentReference(
            $data,
            trim((string) ($data['id'] ?? '')),
            (string) $order['pay_no'],
            $product,
            $order['amount'] ?? null,
            false
        );
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($status === 'failed') {
            throw new PaymentDefinitiveException('AdaPay明确拒绝下单：' . $this->responseMessage($data, $status), 40200, [
                'channel_error_code' => trim((string) ($data['error_code'] ?? '')),
            ]);
        }
        if (!in_array($status, ['pending', 'succeeded'], true)) {
            throw new PaymentUncertainException('AdaPay下单返回未识别状态', 40200, [
                'payment_id' => trim((string) ($data['id'] ?? '')),
                'channel_status' => $status,
            ]);
        }

        return $data;
    }

    /**
     * 构建上游公共请求参数。
     *
     * 通知地址必须指向当前支付单的公共回调路由，避免渠道结果绕过统一回调服务。
     *
     * @param array<string, mixed> $order 标准支付参数
     * @param string $product AdaPay 支付产品
     * @return array<string, mixed> AdaPay 下单公共参数
     */
    private function basePayload(array $order, string $product): array
    {
        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $payNo) !== 1) {
            throw new PaymentDefinitiveException('AdaPay支付单号必须为1-64位字母、数字或下划线', 40200);
        }
        $clientIp = trim((string) ($order['client_ip'] ?? ''));
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new PaymentDefinitiveException('AdaPay下单client_ip无效', 40200);
        }
        $notifyUrl = trim((string) ($order['callback_url'] ?? ''));
        if (filter_var($notifyUrl, FILTER_VALIDATE_URL) === false || parse_url($notifyUrl, PHP_URL_QUERY) !== null) {
            throw new PaymentDefinitiveException('AdaPay支付通知地址无效或包含查询参数', 40200);
        }
        $notifyPath = (string) parse_url($notifyUrl, PHP_URL_PATH);
        if (!str_ends_with($notifyPath, '/api/pay/' . $payNo . '/callback')) {
            throw new PaymentDefinitiveException('AdaPay支付通知必须复用公共支付回调路由', 40200);
        }
        $subject = trim((string) ($order['subject'] ?? ''));
        if ($subject === '') {
            throw new PaymentDefinitiveException('AdaPay商品标题不能为空', 40200);
        }

        return [
            'order_no' => $payNo,
            'pay_amt' => FormatHelper::amount($this->positiveCents($order['amount'] ?? null, 'AdaPay支付金额必须是正整数分')),
            'goods_title' => mb_strcut($subject, 0, 64, 'UTF-8'),
            'goods_desc' => mb_strcut($subject, 0, in_array($product, [self::PRODUCT_WX_PUB, self::PRODUCT_WX_LITE], true) ? 42 : 127, 'UTF-8'),
            'device_info' => ['device_ip' => $clientIp],
            'currency' => 'cny',
            'notify_url' => $notifyUrl,
        ];
    }

    /**
     * 构建待确认的标准支付结果。
     *
     * AdaPay 创建 Payment 对象不等同于资金成功，支付状态由通知或主动查单确认。
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $payParams
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function paymentResult(array $order, string $product, string $page, array $payParams, array $data): array
    {
        $paymentId = trim((string) $data['id']);

        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $this->payTypeCode($order),
            'pay_product' => $product,
            'pay_action' => 'payments.create',
            'pay_params' => $payParams,
            'chan_order_no' => $paymentId,
            'chan_trade_no' => trim((string) ($data['out_trans_id'] ?? '')),
            'channel_context' => [
                'payment_id' => $paymentId,
                'pay_channel' => $product,
            ],
        ]);
    }

    /**
     * 解析 AdaPay expend.pay_info 承接信息。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payInfo(array $data, string $product): array
    {
        $expend = $data['expend'] ?? null;
        $value = is_array($expend) ? ($expend['pay_info'] ?? null) : null;
        if (is_array($value)) {
            $payInfo = $value;
        } elseif (is_string($value) && $value !== '') {
            try {
                $payInfo = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new PaymentUncertainException('AdaPay下单已创建支付对象，但pay_info不是合法JSON', 40200, [
                    'payment_id' => trim((string) ($data['id'] ?? '')),
                    'pay_product' => $product,
                ]);
            }
        } else {
            $payInfo = null;
        }
        if (!is_array($payInfo) || $payInfo === [] || array_is_list($payInfo)) {
            throw new PaymentUncertainException('AdaPay下单已创建支付对象，但pay_info结构无效', 40200, [
                'payment_id' => trim((string) ($data['id'] ?? '')),
                'pay_product' => $product,
            ]);
        }

        return $payInfo;
    }

    /**
     * 校验支付引用号。
     *
     * 下单响应必须返回应用 ID；查单响应允许省略，但一旦返回就必须与当前通道一致。
     *
     * @param array<string, mixed> $data AdaPay 响应
     * @param string $paymentId 预期 Payment 对象 ID
     * @param string $payNo 本地支付单号
     * @param string $product 预期支付产品
     * @param mixed $amount 本地整数分金额
     * @param bool $query 是否为查单响应
     */
    private function assertPaymentReference(
        array $data,
        string $paymentId,
        string $payNo,
        string $product,
        mixed $amount,
        bool $query
    ): void {
        $exception = PaymentUncertainException::class;
        if ($paymentId === '' || !hash_equals($paymentId, trim((string) ($data['id'] ?? '')))) {
            throw new $exception('AdaPay响应支付对象ID缺失或不一致', 40200);
        }
        if ($payNo === '' || !hash_equals($payNo, trim((string) ($data['order_no'] ?? '')))) {
            throw new $exception('AdaPay响应商户订单号不一致', 40200);
        }
        if (!hash_equals($product, trim((string) ($data['pay_channel'] ?? '')))) {
            throw new $exception('AdaPay响应支付产品不一致', 40200);
        }
        $expectedAmount = $this->positiveCents($amount, 'AdaPay本地订单金额无效');
        $actualAmount = $this->yuanToCents($data['pay_amt'] ?? null, 'AdaPay响应金额', $exception);
        if ($actualAmount !== $expectedAmount) {
            throw new $exception('AdaPay响应金额不一致', 40200);
        }
        $appId = trim((string) ($data['app_id'] ?? ''));
        if (!$query && ($appId === '' || !hash_equals($this->configText('app_id'), $appId))) {
            throw new $exception('AdaPay下单响应应用ID不一致', 40200);
        }
        if ($query && $appId !== '' && !hash_equals($this->configText('app_id'), $appId)) {
            throw new $exception('AdaPay查单响应应用ID不一致', 40200);
        }
        $currency = strtolower(trim((string) ($data['currency'] ?? '')));
        if ($currency !== '' && $currency !== 'cny') {
            throw new $exception('AdaPay响应币种不是cny', 40200);
        }
    }

    /**
     * 校验通知对应的本地支付单。
     *
     * @param PayOrder $payOrder 本地支付单
     * @param string $paymentId AdaPay Payment 对象 ID
     * @param string $product AdaPay 支付产品
     * @param int $paidAmount 实付金额，单位为分
     * @param array<string, mixed> $payload 已验签通知数据
     */
    private function assertNotifyOrder(PayOrder $payOrder, string $paymentId, string $product, int $paidAmount, array $payload): void
    {
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId > 0 && (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('AdaPay回调支付单不属于当前通道', 40200);
        }
        if ((int) $payOrder->pay_amount !== $paidAmount) {
            throw new PaymentException('AdaPay回调金额与本地支付单不一致', 40200);
        }
        $storedPaymentId = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedPaymentId === '' || !hash_equals($storedPaymentId, $paymentId)) {
            throw new PaymentException('AdaPay回调支付对象ID与本地记录不一致', 40200);
        }

        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $expectedProduct = trim((string) ($context['pay_product'] ?? ''));
        $channelContext = (array) ($context['channel_context'] ?? []);
        $contextPaymentId = trim((string) ($channelContext['payment_id'] ?? ''));
        $contextProduct = trim((string) ($channelContext['pay_channel'] ?? ''));
        if ($expectedProduct === '' || !hash_equals($expectedProduct, $product)) {
            throw new PaymentException('AdaPay回调支付产品与下单上下文不一致', 40200);
        }
        if ($contextProduct === '' || !hash_equals($contextProduct, $product)) {
            throw new PaymentException('AdaPay回调支付产品与渠道上下文不一致', 40200);
        }
        if ($contextPaymentId === '' || !hash_equals($contextPaymentId, $paymentId)) {
            throw new PaymentException('AdaPay回调支付对象ID与下单上下文不一致', 40200);
        }

        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        $responseTradeNo = trim((string) ($payload['out_trans_id'] ?? ''));
        if ($responseTradeNo === '') {
            throw new PaymentException('AdaPay成功回调缺少渠道交易号', 40200);
        }
        if ($storedTradeNo !== '' && ($responseTradeNo === '' || !hash_equals($storedTradeNo, $responseTradeNo))) {
            throw new PaymentException('AdaPay回调渠道交易号与本地记录不一致', 40200);
        }
    }

    /**
     * 解析 AdaPay Payment 对象 ID。
     *
     * channel_context 与 chan_order_no 同时存在时必须一致，避免后续操作引用错误支付对象。
     *
     * @param array<string, mixed> $order
     */
    private function paymentId(array $order, bool $query): string
    {
        $context = (array) ($order['channel_context'] ?? []);
        $contextId = trim((string) ($context['payment_id'] ?? ''));
        $channelOrderNo = trim((string) ($order['chan_order_no'] ?? ''));
        if ($contextId !== '' && $channelOrderNo !== '' && !hash_equals($contextId, $channelOrderNo)) {
            $exception = $query ? PaymentUncertainException::class : PaymentDefinitiveException::class;
            throw new $exception('AdaPay支付对象ID上下文不一致', 40200);
        }
        $paymentId = $contextId !== '' ? $contextId : $channelOrderNo;
        if ($paymentId === '') {
            $exception = $query ? PaymentUncertainException::class : PaymentDefinitiveException::class;
            throw new $exception('AdaPay操作缺少Payment对象ID', 40200);
        }

        return $paymentId;
    }

    /**
     * 读取支付单的实际产品。
     *
     * @param array<string, mixed> $order
     */
    private function orderProduct(array $order): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));
        if (!in_array($product, $this->supportedProducts(), true)) {
            throw new PaymentUncertainException('AdaPay订单支付产品上下文缺失或无效', 40200);
        }

        return $product;
    }

    /**
     * 读取微信应用 AppID。
     *
     * @param array<string, mixed> $payment
     */
    private function wxAppId(array $payment, bool $mini): string
    {
        $configured = $this->configText($mini ? 'wechat_mini_app_id' : 'wechat_mp_app_id');
        $provided = trim((string) ($payment['sub_appid'] ?? ''));
        if ($configured !== '' && $provided !== '' && !hash_equals($configured, $provided)) {
            throw new PaymentDefinitiveException('AdaPay微信身份AppID与当前产品配置不一致', 40200);
        }

        return $configured !== '' ? $configured : $provided;
    }

    /**
     * 校验支付宝应用身份作用域。
     *
     * @param array<string, mixed> $payment
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
                throw new PaymentDefinitiveException('AdaPay支付宝身份AppID与当前产品配置不一致', 40200);
            }
        }
    }

    /**
     * 读取微信公众号 OpenID。
     *
     * @param array<string, mixed> $payment
     */
    private function wechatMpOpenId(array $payment): string
    {
        $openid = trim((string) ($payment['openid'] ?? ''));
        $subOpenid = trim((string) ($payment['sub_openid'] ?? ''));
        if ($openid !== '' && $subOpenid !== '' && !hash_equals($openid, $subOpenid)) {
            throw new PaymentDefinitiveException('AdaPay微信公众号openid与sub_openid不一致', 40200);
        }

        return $openid !== '' ? $openid : $subOpenid;
    }

    /**
     * 判断订单是否明确要求小程序支付。
     *
     * @param array<string, mixed> $payment
     */
    private function isMiniIntent(array $payment): bool
    {
        $method = strtolower(trim((string) ($payment['method'] ?? '')));
        if ($method !== '') {
            return $method === 'mini';
        }

        return $this->firstText(
            $payment['mini_openid'] ?? '',
            $payment['wx_login_code'] ?? '',
            $payment['mini_code'] ?? ''
        ) !== ''
            || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 读取标准支付载体参数。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? $order['ext_json'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 读取标准支付方式编码。
     *
     * @param array<string, mixed> $order
     */
    private function payTypeCode(array $order): string
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentDefinitiveException('AdaPay不支持当前支付方式', 40200, ['pay_type' => $payType]);
        }

        return $payType;
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前AdaPay通道未开启该支付产品', 40200, ['product' => $product]);
        }
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

    /**
     * 获取插件支持的支付产品。
     *
     * @return array<int, string>
     */
    private function supportedProducts(): array
    {
        return [
            self::PRODUCT_ALIPAY_PUB,
            self::PRODUCT_WX_PUB,
            self::PRODUCT_ALIPAY_QR,
            self::PRODUCT_WX_LITE,
            self::PRODUCT_UNION_QR,
        ];
    }

    private function validateConfiguration(): void
    {
        $this->requireConfig(
            ['app_id', 'api_key', 'merchant_private_key', 'platform_public_key'],
            'AdaPay基础配置不完整'
        );
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, $this->supportedProducts()) !== []) {
            throw new PaymentException('AdaPay已开通产品配置为空或包含未知产品', 40200);
        }
    }

    /**
     * 读取必填插件配置。
     *
     * @param array<int, string> $fields
     */
    private function requireConfig(array $fields, string $message): void
    {
        foreach ($fields as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentDefinitiveException($message, 40200, ['missing_config' => $field]);
            }
        }
    }

    /**
     * 将 SDK 写操作异常映射为支付领域异常。
     *
     * 请求是否可能已送达由 SDK 标记，结果不确定时不得降级为确定失败。
     */
    private function throwMutationFailure(AdapaySdkException $e, string $message): never
    {
        $exception = $e->isUncertain()
            ? PaymentUncertainException::class
            : PaymentDefinitiveException::class;

        throw new $exception($message . '：' . $e->getMessage(), 40200, $this->sdkError($e));
    }

    /**
     * 映射 SDK 异常。
     *
     * @return array<string, string>
     */
    private function sdkError(AdapaySdkException $e): array
    {
        $code = $e->channelErrorCode();
        return $code === '' ? [] : ['channel_error_code' => $code];
    }

    private function positiveCents(mixed $value, string $message): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9]\d*$/D', trim((string) $value)) !== 1) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return (int) $value;
    }

    /**
     * 将元金额转换为整数分。
     *
     * @param class-string<PaymentException> $exception
     */
    private function yuanToCents(mixed $value, string $field, string $exception = PaymentException::class): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $text, $matches) !== 1) {
            throw new $exception($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    /**
     * 解析 AdaPay 的 yyyyMMddHHmmss 时间。
     */
    private function adapayTime(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{14}$/D', $text) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!YmdHis', $text);

        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    /**
     * 读取上游响应消息。
     *
     * @param array<string, mixed> $data
     */
    private function responseMessage(array $data, string $fallback): string
    {
        $message = trim((string) ($data['error_msg'] ?? ''));
        return $message !== '' ? mb_strcut($message, 0, 180, 'UTF-8') : $fallback;
    }

    private function client(): AdapayClient
    {
        if ($this->client === null) {
            $this->client = new AdapayClient([
                'app_id' => $this->configText('app_id'),
                'api_key' => $this->configText('api_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
            ]);
        }

        return $this->client;
    }

    /**
     * 构建输入框配置项。
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

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
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
