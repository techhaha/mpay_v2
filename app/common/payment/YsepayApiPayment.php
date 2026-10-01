<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\ysepay\YsepayClient;
use app\common\sdk\ysepay\YsepaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use JsonException;
use support\Request;
use support\Response;

/**
 * 银盛支付聚合扫码、H5、JSAPI 与退款插件。
 */
class YsepayApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WEIXIN_JSAPI = 'weixin_jsapi';
    private const PRODUCT_CUPMULAPP = 'cupmulapp';
    private const PRODUCT_ALIPAY_SCAN = '1903000';
    private const PRODUCT_WEIXIN_SCAN = '1902000';
    private const PRODUCT_UNIONPAY_SCAN = '9001002';

    /** @var array<int, string> */
    private const PRODUCTS = [
        self::PRODUCT_ALIPAY_JSAPI,
        self::PRODUCT_ALIPAY_H5,
        self::PRODUCT_WEIXIN_JSAPI,
        self::PRODUCT_CUPMULAPP,
        self::PRODUCT_ALIPAY_SCAN,
        self::PRODUCT_WEIXIN_SCAN,
        self::PRODUCT_UNIONPAY_SCAN,
    ];

    private const METHOD_QRCODE = 'ysepay.online.qrcodepay';
    private const METHOD_ALIPAY_JSAPI = 'ysepay.online.alijsapi.pay';
    private const METHOD_WEIXIN_JSAPI = 'ysepay.online.weixin.pay';
    private const METHOD_UNIONPAY_USER_ID = 'ysepay.online.cupgetmulapp.userid';
    private const METHOD_UNIONPAY_JSAPI = 'ysepay.online.cupmulapp.qrcodepay';
    private const METHOD_ALIPAY_H5 = 'ysepay.online.wap.directpay.createbyuser';
    private const METHOD_REFUND = 'ysepay.online.trade.refund';

    private ?YsepayClient $client = null;
    private ?YsepayClient $injectedClient;
    private PayOrderRepository $payOrderRepository;

    /** @var array<string, mixed> */
    protected array $paymentInfo = [
        'code' => 'ysepay_api',
        'name' => '银盛支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.ysepay.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造银盛支付插件。
     */
    public function __construct(
        ?PayOrderRepository $payOrderRepository = null,
        ?YsepayClient $client = null
    ) {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
        $this->injectedClient = $client;
    }

    /**
     * 初始化支付插件。
     *
     * @param array<string, mixed> $channelConfig
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
        $this->client = $this->injectedClient;
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
            $this->requiredInput('partner_id', '服务商商户号'),
            $this->requiredInput('seller_id', '收款商户号'),
            $this->requiredInput('business_code', '银盛业务代码'),
            [
                'type' => 'upload',
                'field' => 'platform_cert_path',
                'title' => '银盛平台公钥证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [['required' => true, 'message' => '银盛平台公钥证书不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'private_cert_path',
                'title' => '商户 PFX/P12 证书',
                'value' => '',
                'props' => $this->uploadProps('.pfx,.p12'),
                'validate' => [['required' => true, 'message' => '商户 PFX/P12 证书不能为空']],
            ],
            [
                'type' => 'password',
                'field' => 'private_cert_password',
                'title' => '商户证书密码',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户证书密码不能为空']],
            ],
            $this->optionalInput('wx_mp_app_id', '微信公众号 AppID'),
            ['type' => 'password', 'field' => 'wx_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            $this->optionalInput('wx_mini_app_id', '微信小程序 AppID'),
            ['type' => 'password', 'field' => 'wx_mini_app_secret', 'title' => '微信小程序 AppSecret', 'value' => ''],
            $this->optionalInput('wx_mini_launch_path', '微信小程序支付页路径', 'pages/pay/index'),
            $this->optionalInput('alipay_oauth_app_id', '支付宝授权应用 AppID'),
            [
                'type' => 'upload',
                'field' => 'alipay_oauth_private_key_path',
                'title' => '支付宝授权应用私钥',
                'value' => '',
                'props' => $this->uploadProps('.key,.pem'),
            ],
            [
                'type' => 'upload',
                'field' => 'alipay_oauth_public_key_path',
                'title' => '支付宝公钥/证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
            ],
            $this->optionalInput('unionpay_app_up_identifier', '银联 appUpIdentifier'),
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_JSAPI => '支付宝生活号 JSAPI',
                self::PRODUCT_ALIPAY_H5 => '支付宝 H5 自动表单',
                self::PRODUCT_WEIXIN_JSAPI => '微信公众号/小程序 JSAPI',
                self::PRODUCT_CUPMULAPP => '银联行业码 JSAPI',
                self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
                self::PRODUCT_WEIXIN_SCAN => '微信扫码',
                self::PRODUCT_UNIONPAY_SCAN => '银联扫码',
            ]),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        if ($this->isWechatMiniOrder($order)) {
            $this->ensureProduct(self::PRODUCT_WEIXIN_JSAPI);

            return $this->weixinJsapiPay($order, true);
        }
        if ($this->isUnionpayJsapiOrder($order)) {
            $this->ensureProduct(self::PRODUCT_CUPMULAPP);

            return $this->unionpayJsapiPay($order);
        }

        return $this->executeDirectPaymentProduct($order, $this->paymentHandlers($order), '银盛');
    }

    /**
     * 检查支付所需的用户身份。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    public function identityRequirement(array $order): ?array
    {
        if ($this->isWechatMiniOrder($order)) {
            $this->ensureProduct(self::PRODUCT_WEIXIN_JSAPI);

            return $this->weixinIdentityRequirement($order, true);
        }
        if ($this->isUnionpayJsapiOrder($order)) {
            $this->ensureProduct(self::PRODUCT_CUPMULAPP);

            return $this->unionpayIdentityRequirement($order);
        }

        $handlers = $this->directPaymentUsableHandlers($order, $this->paymentHandlers($order));
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));
        if (($candidates[0] ?? '') !== 'jsapi') {
            return null;
        }

        return match ((string) ($order['pay_type_code'] ?? '')) {
            'alipay' => $this->alipayIdentityRequirement($order),
            'wxpay' => $this->weixinIdentityRequirement($order, false),
            default => null,
        };
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银盛插件暂不支持主动查单', 40200);
    }

    /**
     * 关闭支付订单。
     *
     * @param array<string, mixed> $order
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银盛插件暂不支持关单', 40200);
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $payNo = $this->payNo($order['pay_no'] ?? '');
        $refundNo = $this->refundNo($order['refund_no'] ?? '');
        $refundAmount = $this->positiveCents($order['refund_amount'] ?? null, '银盛退款金额必须为正整数分');
        $channelTradeNo = $this->requiredText($order['chan_trade_no'] ?? '', '银盛退款缺少原交易流水号');
        $channelOrderNo = $this->channelOrderReference(
            $order['chan_order_no'] ?? '',
            '银盛退款缺少合规的原渠道订单号'
        );
        $channelContext = (array) ($order['channel_context'] ?? []);
        $shopdate = trim((string) ($channelContext['shopdate'] ?? ''));
        if (preg_match('/^\d{8}$/D', $shopdate) !== 1 || !hash_equals(substr($channelOrderNo, 0, 8), $shopdate)) {
            throw new PaymentDefinitiveException('银盛退款缺少与原交易一致的 shopdate 上下文', 40200);
        }
        $this->assertOriginalChannelContext($channelContext, $payNo);
        $this->assertSameText(
            $channelOrderNo,
            $channelContext['out_trade_no'] ?? '',
            '银盛退款原渠道订单号与下单上下文不一致'
        );

        $data = $this->execute(self::METHOD_REFUND, [
            'out_trade_no' => $channelOrderNo,
            'shopdate' => $shopdate,
            'trade_no' => $channelTradeNo,
            'refund_amount' => FormatHelper::amount($refundAmount),
            'refund_reason' => '申请退款',
            'out_request_no' => $refundNo,
        ], [], '退款');

        $this->assertSameText($channelOrderNo, $data['out_trade_no'] ?? '', '银盛退款响应原订单号不一致', true);
        $this->assertSameText($channelTradeNo, $data['trade_no'] ?? '', '银盛退款响应原交易流水号不一致', true);
        $this->assertSameText($refundNo, $data['out_request_no'] ?? '', '银盛退款响应请求号不一致', true);
        if ($this->yuanToCents($data['refund_amount'] ?? null, '银盛退款响应金额', true) !== $refundAmount) {
            throw new PaymentUncertainException('银盛退款响应金额与请求不一致', 40200);
        }
        $refundSn = $this->requiredText(
            $data['refundsn'] ?? '',
            '银盛退款响应缺少退款流水号',
            true
        );

        // 银盛公开文档明确 code=10000 仅表示受理，不能当作最终退款成功。
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'refund_no' => $refundNo,
            'pay_no' => $payNo,
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $refundSn,
            'message' => '银盛退款申请已受理，最终结果待渠道通知或对账确认',
        ];
    }

    /**
     * 校验并解析支付通知。
     *
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = $request->post();
        if (!is_array($payload)) {
            throw new PaymentException('银盛回调载荷格式无效', 40200);
        }
        try {
            $verified = $this->client()->verify($payload);
        } catch (YsepaySdkException $e) {
            throw new PaymentException('银盛回调验签失败', 40200);
        }
        if (!$verified) {
            throw new PaymentException('银盛回调验签失败', 40200);
        }
        $this->assertSameText('RSA', $payload['sign_type'] ?? '', '银盛回调签名类型不匹配');
        $this->assertSameText(
            'directpay.status.sync',
            $payload['notify_type'] ?? '',
            '银盛回调通知类型不匹配'
        );

        $callbackContext = $this->decodeNotificationContext($payload['extra_common_param'] ?? '');
        $payNo = $this->payNo($callbackContext['pay_no'] ?? '');
        $channelOrderNo = $this->channelOrderReference(
            $payload['out_trade_no'] ?? '',
            '银盛回调渠道订单号格式无效'
        );
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('银盛回调支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $storedContext = $this->storedPaymentContext($payOrder);
        $this->assertNotifyContext($callbackContext, $storedContext, $payOrder, $payNo, $channelOrderNo);

        $amount = $this->yuanToCents($payload['total_amount'] ?? null, '银盛回调金额');
        if ($amount !== (int) $payOrder->pay_amount) {
            throw new PaymentException('银盛回调金额与支付单不一致', 40200, ['pay_no' => $payNo]);
        }
        $tradeNo = $this->requiredText($payload['trade_no'] ?? '', '银盛回调缺少交易流水号');
        $storedOrderNo = trim((string) ($payOrder->channel_order_no ?? ''));
        if ($storedOrderNo !== '' && !hash_equals($storedOrderNo, $channelOrderNo)) {
            throw new PaymentException('银盛回调渠道订单号与支付单不一致', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = trim((string) ($payOrder->channel_trade_no ?? ''));
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $tradeNo)) {
            throw new PaymentException('银盛回调交易流水号与支付单不一致', 40200, ['pay_no' => $payNo]);
        }

        $tradeStatus = $this->requiredText($payload['trade_status'] ?? '', '银盛回调缺少交易状态');
        $status = match ($tradeStatus) {
            'TRADE_SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'TRADE_CLOSED', 'TRADE_FAILD' => PaymentPluginStatusConstant::FAILED,
            'WAIT_BUYER_PAY', 'WAIT_SELLER_SEND_GOODS', 'TRADE_PROCESS',
            'TRADE_USERPAYING', 'TRADE_ABNORMALITY' => PaymentPluginStatusConstant::PENDING,
            default => throw new PaymentException('银盛回调交易状态不在已确认枚举内', 40200, ['pay_no' => $payNo]),
        };

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'message' => $tradeStatus,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $tradeNo,
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
     * 构建当前订单可用的支付处理器。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function paymentHandlers(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');

        return [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WEIXIN_JSAPI,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                    ? $this->weixinJsapiPay($order, false)
                    : $this->alipayJsapiPay($order),
            ],
            'h5' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5Pay($order),
            ],
            'jump' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5Pay($order),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WEIXIN_SCAN,
                    'bank' => self::PRODUCT_UNIONPAY_SCAN,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ];
    }

    /**
     * 发起二维码支付。
     *
     * @param array<string, mixed> $order
     */
    private function qrcodePay(array $order): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        $product = match ($payType) {
            'alipay' => self::PRODUCT_ALIPAY_SCAN,
            'wxpay' => self::PRODUCT_WEIXIN_SCAN,
            'bank' => self::PRODUCT_UNIONPAY_SCAN,
            default => throw new UnsupportedPaymentOperationException('银盛不支持当前扫码支付方式', 40200),
        };
        $this->ensureProduct($product);
        $payload = $this->basePayload(
            $order,
            $product,
            self::METHOD_QRCODE,
            $product
        ) + [
            'bank_type' => $product,
            'submer_ip' => $this->requiredText($order['client_ip'] ?? '', '银盛扫码下单缺少客户端 IP'),
        ];
        $data = $this->execute(self::METHOD_QRCODE, $payload, [
            'notify_url' => (string) ($order['callback_url'] ?? ''),
        ], '扫码下单');
        $this->assertPaymentResponse($data, $order, '银盛扫码下单', $product, (string) $payload['out_trade_no']);
        $qrcode = $this->requiredText(
            $data['source_qr_code_url'] ?? '',
            '银盛扫码响应缺少 source_qr_code_url',
            true
        );
        if (strlen($qrcode) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $qrcode) === 1) {
            throw new PaymentUncertainException('银盛扫码响应二维码内容格式无效', 40200);
        }

        return $this->payResult('qrcode', $payType, $product, self::METHOD_QRCODE, [
            'qrcode' => $qrcode,
        ], $data, $order, $product);
    }

    /**
     * 发起支付宝 H5 支付。
     *
     * @param array<string, mixed> $order
     */
    private function alipayH5Pay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_ALIPAY_H5);
        $bizParams = $this->basePayload(
            $order,
            self::PRODUCT_ALIPAY_H5,
            self::METHOD_ALIPAY_H5,
            self::PRODUCT_ALIPAY_SCAN
        ) + [
            'pay_mode' => 'native',
            'bank_type' => self::PRODUCT_ALIPAY_SCAN,
        ];
        try {
            $form = $this->client()->pageRequest(self::METHOD_ALIPAY_H5, $bizParams, [
                'notify_url' => (string) ($order['callback_url'] ?? ''),
                'return_url' => (string) ($order['return_url'] ?? ''),
            ]);
        } catch (YsepaySdkException $e) {
            throw new PaymentDefinitiveException('银盛支付宝 H5 表单构造失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('jump', 'alipay', self::PRODUCT_ALIPAY_H5, self::METHOD_ALIPAY_H5, [
            'method' => 'post',
            'action' => $form['action'],
            'payload' => $form['payload'],
        ], ['out_trade_no' => (string) $bizParams['out_trade_no']], $order, self::PRODUCT_ALIPAY_SCAN);
    }

    /**
     * 发起支付宝 JSAPI 支付。
     *
     * @param array<string, mixed> $order
     */
    private function alipayJsapiPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_ALIPAY_JSAPI);
        $payment = $this->paymentPayload($order);
        $buyerId = $this->requiredIdentity($payment['buyer_id'] ?? '', '银盛支付宝生活号支付缺少 buyer_id');
        $payload = $this->basePayload(
            $order,
            self::PRODUCT_ALIPAY_JSAPI,
            self::METHOD_ALIPAY_JSAPI
        ) + [
            'payer_ip' => $this->requiredText($order['client_ip'] ?? '', '银盛支付宝生活号下单缺少客户端 IP'),
            'buyer_id' => $buyerId,
        ];
        $data = $this->execute(self::METHOD_ALIPAY_JSAPI, $payload, [
            'notify_url' => (string) ($order['callback_url'] ?? ''),
        ], '支付宝生活号下单');
        $this->assertPaymentResponse($data, $order, '银盛支付宝生活号下单', '', (string) $payload['out_trade_no']);
        $payInfo = $this->jsonObject($data['jsapi_pay_info'] ?? '', '银盛支付宝 jsapi_pay_info');
        $tradeNo = $this->requiredText($payInfo['tradeNO'] ?? '', '银盛支付宝 jsapi_pay_info 缺少 tradeNO', true);

        return $this->payResult('jsapi', 'alipay', self::PRODUCT_ALIPAY_JSAPI, self::METHOD_ALIPAY_JSAPI, [
            'tradeNO' => $tradeNo,
        ], $data, $order);
    }

    /**
     * 发起微信 JSAPI 支付。
     *
     * @param array<string, mixed> $order
     */
    private function weixinJsapiPay(array $order, bool $mini): array
    {
        $this->ensureProduct(self::PRODUCT_WEIXIN_JSAPI);
        $payment = $this->paymentPayload($order);
        $appId = $this->requiredText(
            $this->configText($mini ? 'wx_mini_app_id' : 'wx_mp_app_id'),
            $mini ? '银盛微信小程序未配置 AppID' : '银盛微信公众号未配置 AppID'
        );
        $this->assertWechatAppScope($payment, $appId, $mini);
        $openid = $mini
            ? $this->requiredIdentity($payment['mini_openid'] ?? '', '银盛微信小程序支付缺少 mini_openid')
            : $this->wechatMpOpenid($payment);

        $payload = $this->basePayload(
            $order,
            self::PRODUCT_WEIXIN_JSAPI,
            self::METHOD_WEIXIN_JSAPI,
            self::PRODUCT_WEIXIN_SCAN
        ) + [
            'payer_ip' => $this->requiredText($order['client_ip'] ?? '', '银盛微信 JSAPI 下单缺少客户端 IP'),
            'appid' => $appId,
            // 银盛接口统一使用 sub_openid；来源仍按 MP/mini 作用域严格区分。
            'sub_openid' => $openid,
            'is_minipg' => $mini ? '1' : '2',
        ];
        $data = $this->execute(self::METHOD_WEIXIN_JSAPI, $payload, [
            'notify_url' => (string) ($order['callback_url'] ?? ''),
        ], $mini ? '微信小程序下单' : '微信公众号下单');
        $this->assertPaymentResponse($data, $order, '银盛微信 JSAPI 下单', '', (string) $payload['out_trade_no']);
        $payInfo = $this->jsonObject($data['jsapi_pay_info'] ?? '', '银盛微信 jsapi_pay_info');
        $strictPayInfo = [];
        foreach (['appId', 'timeStamp', 'nonceStr', 'package', 'signType', 'paySign'] as $field) {
            $strictPayInfo[$field] = $this->requiredText(
                $payInfo[$field] ?? '',
                '银盛微信 jsapi_pay_info 缺少 ' . $field,
                true
            );
        }
        if (!hash_equals($appId, $strictPayInfo['appId'])) {
            throw new PaymentUncertainException('银盛微信 jsapi_pay_info AppID 作用域不一致', 40200);
        }

        return $this->payResult('jsapi', 'wxpay', self::PRODUCT_WEIXIN_JSAPI, self::METHOD_WEIXIN_JSAPI, $strictPayInfo, $data, $order, self::PRODUCT_WEIXIN_SCAN);
    }

    /**
     * 发起银联 JSAPI 支付。
     *
     * @param array<string, mixed> $order
     */
    private function unionpayJsapiPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_CUPMULAPP);
        $payment = $this->paymentPayload($order);
        $userId = trim((string) ($payment['unionpay_user_id'] ?? ''));
        if ($userId === '') {
            $authCode = $this->requiredIdentity(
                $payment['unionpay_auth_code'] ?? '',
                '银盛银联行业码支付缺少 unionpay_auth_code'
            );
            $identifier = $this->requiredText(
                $this->configText('unionpay_app_up_identifier'),
                '银盛银联行业码未配置 appUpIdentifier'
            );
            $identity = $this->execute(self::METHOD_UNIONPAY_USER_ID, [
                'authCode' => $authCode,
                'appUpIdentifier' => $identifier,
            ], [], '银联 userAuth 身份交换');
        // rainbow_legacy 档案固定使用该字段名；公开资料只确认必须先交换 userId。
            $userId = $this->requiredIdentity(
                $identity['userId'] ?? '',
                '银盛银联身份交换响应缺少 userId',
                true
            );
        } else {
            $userId = $this->requiredIdentity($userId, '银盛银联 userId 格式无效');
        }

        $payload = $this->basePayload(
            $order,
            self::PRODUCT_CUPMULAPP,
            self::METHOD_UNIONPAY_JSAPI,
            self::PRODUCT_UNIONPAY_SCAN
        ) + [
            'spbill_create_ip' => $this->requiredText($order['client_ip'] ?? '', '银盛银联行业码下单缺少客户端 IP'),
            'bank_type' => self::PRODUCT_UNIONPAY_SCAN,
            'userId' => $userId,
        ];
        $data = $this->execute(self::METHOD_UNIONPAY_JSAPI, $payload, [
            'notify_url' => (string) ($order['callback_url'] ?? ''),
        ], '银联行业码下单');
        $this->assertPaymentResponse(
            $data,
            $order,
            '银盛银联行业码下单',
            self::PRODUCT_UNIONPAY_SCAN,
            (string) $payload['out_trade_no']
        );
        $url = $this->httpsUrl($data['web_url'] ?? '', '银盛银联行业码响应 web_url');

        return $this->payResult('jump', 'bank', self::PRODUCT_CUPMULAPP, self::METHOD_UNIONPAY_JSAPI, [
            'url' => $url,
        ], $data, $order, self::PRODUCT_UNIONPAY_SCAN);
    }

    /**
     * 检查支付宝支付所需的用户身份。
     *
     * @param array<string, mixed> $order
     */
    private function alipayIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if (trim((string) ($payment['buyer_id'] ?? '')) !== '') {
            $this->requiredIdentity($payment['buyer_id'], '银盛支付宝 buyer_id 格式无效');
            return null;
        }
        foreach (['alipay_oauth_app_id', 'alipay_oauth_private_key_path', 'alipay_oauth_public_key_path'] as $field) {
            if ($this->configText($field) === '') {
                throw new PaymentDefinitiveException('银盛支付宝生活号缺少 buyer_id，且支付宝授权配置不完整', 40200);
            }
        }

        return [
            'provider' => 'alipay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_ALIPAY_JSAPI,
            'auth_type' => 'alipay_oauth',
            'identity_field' => 'buyer_id',
            'identity_aliases' => [],
            'app_id' => $this->configText('alipay_oauth_app_id'),
            'scope' => 'auth_base',
            '_alipay_config' => [
                'mode' => 'key',
                'app_id' => $this->configText('alipay_oauth_app_id'),
                'private_key' => $this->privateAssetContents(
                    $this->configText('alipay_oauth_private_key_path'),
                    ['key', 'pem'],
                    '支付宝授权应用私钥'
                ),
                'alipay_public_key' => $this->privateAssetContents(
                    $this->configText('alipay_oauth_public_key_path'),
                    ['cer', 'crt', 'pem'],
                    '支付宝公钥/证书'
                ),
                'sandbox' => false,
            ],
            'message' => '银盛支付宝生活号支付需要当前支付宝应用作用域的 buyer_id',
        ];
    }

    /**
     * 检查微信支付所需的用户身份。
     *
     * @param array<string, mixed> $order
     */
    private function weixinIdentityRequirement(array $order, bool $mini): ?array
    {
        $payment = $this->paymentPayload($order);
        $appIdField = $mini ? 'wx_mini_app_id' : 'wx_mp_app_id';
        $appSecretField = $mini ? 'wx_mini_app_secret' : 'wx_mp_app_secret';
        $appId = $this->configText($appIdField);
        $this->assertWechatAppScope($payment, $appId, $mini);
        $openid = $mini
            ? trim((string) ($payment['mini_openid'] ?? ''))
            : $this->wechatMpOpenid($payment, false);
        if ($openid !== '') {
            $this->requiredIdentity($openid, '银盛微信身份格式无效');
            return null;
        }
        $appSecret = $this->configText($appSecretField);
        if ($appId === '' || $appSecret === '') {
            throw new PaymentDefinitiveException($mini
                ? '银盛微信小程序缺少 mini_openid，且小程序授权配置不完整'
                : '银盛微信公众号缺少 openid，且公众号授权配置不完整', 40200);
        }

        return [
            'provider' => 'wxpay',
            'product' => $mini ? 'mini' : 'mp',
            'channel_product' => self::PRODUCT_WEIXIN_JSAPI,
            'auth_type' => $mini ? 'mini_program' : 'wechat_oauth',
            'identity_field' => $mini ? 'mini_openid' : 'openid',
            'identity_aliases' => $mini ? [] : ['sub_openid'],
            'app_id' => $appId,
            '_app_secret' => $appSecret,
            'scope' => 'snsapi_base',
            'mini_path' => $mini ? $this->configText('wx_mini_launch_path') : '',
            'mini_launch_type' => $mini && strtolower((string) ($order['_env'] ?? '')) === 'wechat'
                ? 'url_link'
                : 'url_scheme',
            'env_version' => 'release',
            'message' => $mini
                ? '银盛微信小程序支付需要当前小程序作用域的 mini_openid'
                : '银盛微信公众号支付需要当前公众号作用域的 openid',
        ];
    }

    /**
     * 检查银联支付所需的用户身份。
     *
     * @param array<string, mixed> $order
     */
    private function unionpayIdentityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['unionpay_user_id'] ?? '', $payment['unionpay_auth_code'] ?? '') !== '') {
            return null;
        }
        $identifier = $this->configText('unionpay_app_up_identifier');
        if ($identifier === '') {
            throw new PaymentDefinitiveException('银盛银联行业码未配置 appUpIdentifier', 40200);
        }

        return [
            'provider' => 'unionpay',
            'product' => 'jsapi',
            'channel_product' => self::PRODUCT_CUPMULAPP,
            'auth_type' => 'unionpay_user_auth',
            'identity_field' => 'unionpay_auth_code',
            'identity_aliases' => [],
            'app_id' => $identifier,
            'message' => '银盛银联行业码支付需要先完成现有银联 userAuth 授权',
        ];
    }

    /**
     * 构建上游公共请求参数。
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function basePayload(
        array $order,
        string $product,
        string $method,
        string $bankType = ''
    ): array {
        $payNo = $this->payNo($order['pay_no'] ?? '');
        $amount = $this->positiveCents($order['amount'] ?? null, '银盛下单金额必须为正整数分');
        $channelOrderNo = $this->channelOrderNo($payNo);
        $shopdate = substr($channelOrderNo, 0, 8);

        return [
            'out_trade_no' => $channelOrderNo,
            'shopdate' => $shopdate,
            'subject' => mb_strcut($this->requiredText($order['subject'] ?? '', '银盛下单主题不能为空'), 0, 250, 'UTF-8'),
            'total_amount' => FormatHelper::amount($amount),
            'currency' => 'CNY',
            'seller_id' => $this->sellerId(),
            'timeout_express' => '2h',
            'business_code' => $this->businessCode(),
            'extra_common_param' => $this->notificationContext(
                $payNo,
                $channelOrderNo,
                $shopdate,
                $product,
                $method,
                $bankType
            ),
        ];
    }

    /**
     * 校验支付响应。
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $order
     */
    private function assertPaymentResponse(
        array $data,
        array $order,
        string $scene,
        string $bankType = '',
        string $expectedChannelOrderNo = ''
    ): void {
        $payNo = $this->payNo($order['pay_no'] ?? '');
        $expectedChannelOrderNo = $expectedChannelOrderNo !== ''
            ? $expectedChannelOrderNo
            : $this->channelOrderNo($payNo);
        $this->assertSameText(
            $expectedChannelOrderNo,
            $data['out_trade_no'] ?? '',
            $scene . '响应订单号不一致',
            true
        );
        if (($data['total_amount'] ?? '') !== ''
            && $this->yuanToCents($data['total_amount'], $scene . '响应金额', true) !== (int) $order['amount']) {
            throw new PaymentUncertainException($scene . '响应金额与请求不一致', 40200);
        }
        if (($data['currency'] ?? '') !== '' && !hash_equals('CNY', trim((string) $data['currency']))) {
            throw new PaymentUncertainException($scene . '响应币种不是 CNY', 40200);
        }
        if ($bankType !== '') {
            $this->assertSameText($bankType, $data['bank_type'] ?? '', $scene . '响应 bank_type 不一致', true);
        }
    }

    /**
     * 构建标准支付结果。
     *
     * @param array<string, mixed> $payParams
     * @param array<string, mixed> $data
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function payResult(
        string $page,
        string $payType,
        string $product,
        string $method,
        array $payParams,
        array $data,
        array $order,
        string $bankType = ''
    ): array {
        $payNo = (string) $order['pay_no'];
        $channelOrderNo = trim((string) ($data['out_trade_no'] ?? ''));
        if ($channelOrderNo === '') {
            $channelOrderNo = $this->channelOrderNo($payNo);
        }
        $shopdate = substr($channelOrderNo, 0, 8);

        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $method,
            'pay_params' => $payParams,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => trim((string) ($data['trade_no'] ?? '')),
            'channel_context' => [
                'partner_id' => $this->partnerId(),
                'seller_id' => $this->sellerId(),
                'business_code' => $this->businessCode(),
                'pay_product' => $product,
                'pay_action' => $method,
                'bank_type' => $bankType,
                'out_trade_no' => $channelOrderNo,
                'shopdate' => $shopdate,
            ],
        ]);
    }

    /**
     * 执行上游 SDK 请求。
     *
     * @param array<string, mixed> $bizContent
     * @param array<string, string> $urls
     * @return array<string, mixed>
     */
    private function execute(
        string $method,
        array $bizContent,
        array $urls,
        string $scene
    ): array {
        try {
            return $this->client()->execute($method, $bizContent, $urls);
        } catch (YsepaySdkException $e) {
            if ($e->isUncertain()) {
                throw new PaymentUncertainException('银盛' . $scene . '结果不确定：' . $e->getMessage(), 40200);
            }
            throw new PaymentDefinitiveException('银盛' . $scene . '失败：' . $e->getMessage(), 40200);
        }
    }

    private function client(): YsepayClient
    {
        if ($this->client === null) {
            try {
                $this->client = new YsepayClient([
                    'partner_id' => $this->partnerId(),
                    'platform_cert' => $this->privateAssetContents(
                        $this->configText('platform_cert_path'),
                        ['cer', 'crt', 'pem'],
                        '银盛平台公钥证书'
                    ),
                    'private_cert' => $this->privateAssetContents(
                        $this->configText('private_cert_path'),
                        ['pfx', 'p12'],
                        '银盛商户 PFX/P12 证书'
                    ),
                    'private_cert_password' => $this->configText('private_cert_password'),
                ]);
            } catch (YsepaySdkException $e) {
                throw new PaymentDefinitiveException('银盛 SDK 初始化失败：' . $e->getMessage(), 40200);
            }
        }

        return $this->client;
    }

    private function validateConfiguration(): void
    {
        $this->partnerId();
        $this->sellerId();
        $this->businessCode();
        $this->requiredText($this->configText('private_cert_password'), '银盛商户证书密码不能为空');
        $this->assertPrivateObjectKeySyntax(
            $this->configText('platform_cert_path'),
            ['cer', 'crt', 'pem'],
            '银盛平台公钥证书'
        );
        $this->assertPrivateObjectKeySyntax(
            $this->configText('private_cert_path'),
            ['pfx', 'p12'],
            '银盛商户 PFX/P12 证书'
        );
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, self::PRODUCTS) !== []) {
            throw new PaymentDefinitiveException('银盛已开通产品为空或包含未知产品', 40200);
        }
        if (in_array(self::PRODUCT_WEIXIN_JSAPI, $enabled, true)
            && $this->configText('wx_mp_app_id') === ''
            && $this->configText('wx_mini_app_id') === '') {
            throw new PaymentDefinitiveException('银盛微信 JSAPI 至少配置公众号或小程序 AppID', 40200);
        }
        if (in_array(self::PRODUCT_CUPMULAPP, $enabled, true)
            && $this->configText('unionpay_app_up_identifier') === '') {
            throw new PaymentDefinitiveException('银盛银联行业码必须配置 appUpIdentifier', 40200);
        }

        $alipayOAuth = [
            'alipay_oauth_app_id',
            'alipay_oauth_private_key_path',
            'alipay_oauth_public_key_path',
        ];
        $configured = array_filter($alipayOAuth, fn (string $field): bool => $this->configText($field) !== '');
        if ($configured !== [] && count($configured) !== count($alipayOAuth)) {
            throw new PaymentDefinitiveException('银盛支付宝授权配置必须完整', 40200);
        }
        if ($configured !== []) {
            $this->assertPrivateObjectKeySyntax(
                $this->configText('alipay_oauth_private_key_path'),
                ['key', 'pem'],
                '支付宝授权应用私钥'
            );
            $this->assertPrivateObjectKeySyntax(
                $this->configText('alipay_oauth_public_key_path'),
                ['cer', 'crt', 'pem'],
                '支付宝公钥/证书'
            );
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
                static fn (mixed $product): string => trim((string) $product),
                $products
            ))))
            : [];
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('银盛产品未在当前通道开通', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
                'product' => $product,
            ]);
        }
    }

    /**
     * 判断订单是否为微信小程序支付。
     *
     * @param array<string, mixed> $order
     */
    private function isWechatMiniOrder(array $order): bool
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
            $payment['mini_code'] ?? '',
            $payment['wx_login_code'] ?? '',
            $payment['mini_app_id'] ?? ''
        ) !== '' || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 判断订单是否为银联 JSAPI 支付。
     *
     * @param array<string, mixed> $order
     */
    private function isUnionpayJsapiOrder(array $order): bool
    {
        if ((string) ($order['pay_type_code'] ?? '') !== 'bank') {
            return false;
        }
        $payment = $this->paymentPayload($order);

        return strtolower(trim((string) ($payment['method'] ?? ''))) === 'jsapi'
            || $this->firstText($payment['unionpay_user_id'] ?? '', $payment['unionpay_auth_code'] ?? '') !== '';
    }

    /**
     * 读取标准支付载体参数。
     *
     * @param array<string, mixed> $order
     */
    private function paymentPayload(array $order): array
    {
        $payment = ((array) ($order['extra'] ?? []))['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 读取微信公众号 OpenID。
     *
     * @param array<string, mixed> $payment
     */
    private function wechatMpOpenid(array $payment, bool $required = true): string
    {
        $openid = trim((string) ($payment['openid'] ?? ''));
        $subOpenid = trim((string) ($payment['sub_openid'] ?? ''));
        if ($openid !== '' && $subOpenid !== '' && !hash_equals($openid, $subOpenid)) {
            throw new PaymentDefinitiveException('银盛微信公众号 openid 与 sub_openid 不一致', 40200);
        }
        $identity = $openid !== '' ? $openid : $subOpenid;
        if (!$required && $identity === '') {
            return '';
        }

        return $this->requiredIdentity($identity, '银盛微信公众号支付缺少 openid');
    }

    /**
     * 校验微信应用身份作用域。
     *
     * @param array<string, mixed> $payment
     */
    private function assertWechatAppScope(array $payment, string $expectedAppId, bool $mini): void
    {
        $provided = $mini
            ? $this->firstText($payment['mini_app_id'] ?? '', $payment['sub_appid'] ?? '')
            : $this->firstText($payment['sub_appid'] ?? '', $payment['wx_app_id'] ?? '');
        if ($provided !== '' && ($expectedAppId === '' || !hash_equals($expectedAppId, $provided))) {
            throw new PaymentDefinitiveException('银盛微信身份 AppID 作用域与通道配置不一致', 40200);
        }
    }

    private function notificationContext(
        string $payNo,
        string $channelOrderNo,
        string $shopdate,
        string $product,
        string $method,
        string $bankType
    ): string {
        try {
            $json = json_encode([
                'v' => 1,
                'pay_no' => $payNo,
                'out_trade_no' => $channelOrderNo,
                'partner_id' => $this->partnerId(),
                'seller_id' => $this->sellerId(),
                'business_code' => $this->businessCode(),
                'currency' => 'CNY',
                'pay_product' => $product,
                'pay_action' => $method,
                'bank_type' => $bankType,
                'shopdate' => $shopdate,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentDefinitiveException('银盛通知校验上下文编码失败', 40200);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * 解码通知上下文。
     *
     * @return array<string, mixed>
     */
    private function decodeNotificationContext(mixed $value): array
    {
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $text) !== 1) {
            throw new PaymentException('银盛回调缺少有效业务校验上下文', 40200);
        }
        $padding = (4 - strlen($text) % 4) % 4;
        $decoded = base64_decode(strtr($text . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new PaymentException('银盛回调业务校验上下文编码无效', 40200);
        }
        try {
            $context = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentException('银盛回调业务校验上下文不是合法 JSON', 40200);
        }
        if (!is_array($context) || array_is_list($context)) {
            throw new PaymentException('银盛回调业务校验上下文结构无效', 40200);
        }

        return $context;
    }

    /**
     * 读取支付单保存的通道上下文。
     *
     * @return array<string, mixed>
     */
    private function storedPaymentContext(PayOrder $payOrder): array
    {
        $ext = $payOrder->ext_json ?? [];
        if (is_string($ext)) {
            $decoded = json_decode($ext, true);
            $ext = is_array($decoded) ? $decoded : [];
        }
        $context = is_array($ext) ? ($ext['payment_context'] ?? []) : [];
        if (!is_array($context)) {
            throw new PaymentException('银盛支付单缺少产品上下文', 40200);
        }

        return $context;
    }

    /**
     * 校验支付通知上下文。
     *
     * @param array<string, mixed> $callbackContext
     * @param array<string, mixed> $storedContext
     */
    private function assertNotifyContext(
        array $callbackContext,
        array $storedContext,
        PayOrder $payOrder,
        string $payNo,
        string $channelOrderNo
    ): void {
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId > 0 && (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('银盛回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }
        if (trim((string) ($payOrder->plugin_code ?? '')) !== ''
            && !hash_equals('ysepay_api', trim((string) $payOrder->plugin_code))) {
            throw new PaymentException('银盛回调支付单插件归属不一致', 40200, ['pay_no' => $payNo]);
        }
        $channelContext = (array) ($storedContext['channel_context'] ?? []);
        $expected = [
            'v' => '1',
            'pay_no' => $payNo,
            'out_trade_no' => $channelOrderNo,
            'partner_id' => $this->partnerId(),
            'seller_id' => $this->sellerId(),
            'business_code' => $this->businessCode(),
            'currency' => 'CNY',
            'pay_product' => trim((string) ($storedContext['pay_product'] ?? '')),
            'pay_action' => trim((string) ($storedContext['pay_action'] ?? '')),
            'bank_type' => trim((string) ($channelContext['bank_type'] ?? '')),
            'shopdate' => trim((string) ($channelContext['shopdate'] ?? '')),
        ];
        if (!in_array($expected['pay_product'], self::PRODUCTS, true)
            || !in_array($expected['pay_product'], $this->enabledProducts(), true)) {
            throw new PaymentException('银盛回调支付产品上下文无效', 40200, ['pay_no' => $payNo]);
        }
        foreach ($expected as $field => $expectedValue) {
            $actual = trim((string) ($callbackContext[$field] ?? ''));
            if ($actual === '' && !in_array($field, ['bank_type'], true)) {
                throw new PaymentException('银盛回调缺少 ' . $field . ' 校验上下文', 40200, ['pay_no' => $payNo]);
            }
            if (!hash_equals($expectedValue, $actual)) {
                throw new PaymentException('银盛回调 ' . $field . ' 校验上下文不一致', 40200, ['pay_no' => $payNo]);
            }
            if (array_key_exists($field, $channelContext)) {
                $storedValue = trim((string) $channelContext[$field]);
                if (!hash_equals($actual, $storedValue)) {
                    throw new PaymentException('银盛回调 ' . $field . ' 与下单快照不一致', 40200, ['pay_no' => $payNo]);
                }
            }
        }
    }

    /**
     * 校验原支付通道上下文。
     *
     * @param array<string, mixed> $context
     */
    private function assertOriginalChannelContext(array $context, string $payNo): void
    {
        foreach ([
            'partner_id' => $this->partnerId(),
            'seller_id' => $this->sellerId(),
            'business_code' => $this->businessCode(),
        ] as $field => $expected) {
            $this->assertSameText($expected, $context[$field] ?? '', '银盛退款原交易 ' . $field . ' 上下文不一致');
        }
        $product = trim((string) ($context['pay_product'] ?? ''));
        if (!in_array($product, self::PRODUCTS, true)) {
            throw new PaymentDefinitiveException('银盛退款原交易产品上下文无效', 40200, ['pay_no' => $payNo]);
        }
        $this->channelOrderReference(
            $context['out_trade_no'] ?? '',
            '银盛退款原交易渠道订单号上下文无效'
        );
    }

    private function partnerId(): string
    {
        $value = $this->configText('partner_id');
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $value) !== 1) {
            throw new PaymentDefinitiveException('银盛服务商商户号格式无效', 40200);
        }

        return $value;
    }

    private function sellerId(): string
    {
        $value = $this->configText('seller_id');
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $value) !== 1) {
            throw new PaymentDefinitiveException('银盛收款商户号格式无效', 40200);
        }

        return $value;
    }

    private function businessCode(): string
    {
        $value = $this->configText('business_code');
        if (preg_match('/^[A-Za-z0-9_-]{1,10}$/D', $value) !== 1) {
            throw new PaymentDefinitiveException('银盛业务代码格式无效', 40200);
        }

        return $value;
    }

    private function payNo(mixed $value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $text) !== 1) {
            throw new PaymentDefinitiveException('银盛本地支付单号格式无效', 40200);
        }

        return $text;
    }

    /**
     * 生成符合银盛格式约束的渠道订单号。
     *
     * 银盛要求 out_trade_no 前八位为交易日期，而 MPAY 本地 pay_no 以 PAY 开头；
     * 因此在插件域内派生稳定的 32 位渠道订单号，并通过签名回传上下文关联本地单号。
     */
    private function channelOrderNo(string $payNo): string
    {
        return date('Ymd') . strtoupper(substr(hash('sha256', $this->partnerId() . '|' . $payNo), 0, 24));
    }

    private function channelOrderReference(mixed $value, string $message): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{8}[A-Za-z0-9_-]{1,24}$/D', $text) !== 1) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return $text;
    }

    private function refundNo(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 32 || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            throw new PaymentDefinitiveException('银盛退款请求号格式无效', 40200);
        }

        return $text;
    }

    private function requiredIdentity(mixed $value, string $message, bool $uncertain = false): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 128 || preg_match('/[\x00-\x20\x7F]/', $text) === 1) {
            $exception = $uncertain ? PaymentUncertainException::class : PaymentDefinitiveException::class;
            throw new $exception($message, 40200);
        }

        return $text;
    }

    private function requiredText(mixed $value, string $message, bool $uncertain = false): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            $exception = $uncertain ? PaymentUncertainException::class : PaymentDefinitiveException::class;
            throw new $exception($message, 40200);
        }

        return $text;
    }

    private function positiveCents(mixed $value, string $message): int
    {
        $text = trim((string) $value);
        if (preg_match('/^[1-9]\d*$/D', $text) !== 1) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return (int) $text;
    }

    private function yuanToCents(mixed $value, string $field, bool $uncertain = false): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/D', $text, $matches) !== 1) {
            $exception = $uncertain ? PaymentUncertainException::class : PaymentException::class;
            throw new $exception($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    private function assertSameText(
        string $expected,
        mixed $actual,
        string $message,
        bool $uncertain = false
    ): void {
        $actualText = trim((string) $actual);
        if ($actualText === '' || !hash_equals($expected, $actualText)) {
            $exception = $uncertain ? PaymentUncertainException::class : PaymentException::class;
            throw new $exception($message, 40200);
        }
    }

    /**
     * 将 JSON 对象解码为关联数组。
     *
     * @return array<string, mixed>
     */
    private function jsonObject(mixed $value, string $field): array
    {
        if (!is_string($value) || trim($value) === '') {
            throw new PaymentUncertainException($field . '为空', 40200);
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentUncertainException($field . '不是合法 JSON', 40200);
        }
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new PaymentUncertainException($field . '结构无效', 40200);
        }

        return $decoded;
    }

    private function httpsUrl(mixed $value, string $field): string
    {
        $url = trim((string) $value);
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '') {
            throw new PaymentUncertainException($field . '不是有效 HTTPS 地址', 40200);
        }

        return $url;
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
     * 构建必填输入框配置项。
     *
     * @return array<string, mixed>
     */
    private function requiredInput(string $field, string $title): array
    {
        return [
            'type' => 'input',
            'field' => $field,
            'title' => $title,
            'value' => '',
            'validate' => [['required' => true, 'message' => $title . '不能为空']],
        ];
    }

    /**
     * 构建可选输入框配置项。
     *
     * @return array<string, mixed>
     */
    private function optionalInput(string $field, string $title, string $value = ''): array
    {
        return ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => $value];
    }

    /**
     * 构建私有文件上传配置。
     *
     * @return array<string, mixed>
     */
    private function uploadProps(string $accept): array
    {
        return [
            'fileUpload' => [
                'scene' => FileConstant::SCENE_CERTIFICATE,
                'visibility' => FileConstant::VISIBILITY_PRIVATE,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'object_key',
                'accept' => $accept,
                'limit' => 1,
                'multiple' => false,
                'showFileList' => true,
            ],
        ];
    }

    /**
     * 校验私有文件对象键格式。
     *
     * @param array<int, string> $extensions
     */
    private function assertPrivateObjectKeySyntax(string $objectKey, array $extensions, string $label): void
    {
        $normalized = str_replace('\\', '/', trim($objectKey));
        $segments = explode('/', $normalized);
        if ($normalized === ''
            || str_contains($normalized, "\0")
            || str_contains($normalized, '://')
            || preg_match('#^storage/private/certificate/[A-Za-z0-9][A-Za-z0-9_./-]*$#D', $normalized) !== 1
            || array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []
            || !in_array(strtolower(pathinfo($normalized, PATHINFO_EXTENSION)), $extensions, true)) {
            throw new PaymentDefinitiveException($label . '必须使用 MPAY 私有证书文件 object_key', 40200);
        }
    }

    /**
     * 读取私有文件资产内容。
     *
     * @param array<int, string> $extensions
     */
    private function privateAssetContents(string $objectKey, array $extensions, string $label): string
    {
        $this->assertPrivateObjectKeySyntax($objectKey, $extensions, $label);
        $normalized = str_replace('\\', '/', trim($objectKey));
        $root = realpath(runtime_path(FileConstant::LOCAL_PRIVATE_DIR . '/certificate'));
        $resolved = realpath(runtime_path($normalized));
        if (!is_string($root) || !is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
            throw new PaymentDefinitiveException($label . '文件不存在或不可读', 40200);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $resolvedNormalized = str_replace('\\', '/', $resolved);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $resolvedNormalized = strtolower($resolvedNormalized);
        }
        if (!str_starts_with($resolvedNormalized, $root)) {
            throw new PaymentDefinitiveException($label . ' object_key 越出 MPAY 私有证书目录', 40200);
        }
        $contents = file_get_contents($resolved);
        if (!is_string($contents) || trim($contents) === '') {
            throw new PaymentDefinitiveException($label . '文件为空', 40200);
        }

        return $contents;
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
