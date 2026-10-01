<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\fuiou\FuiouPayClient;
use app\common\sdk\fuiou\FuiouSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use support\Request;
use support\Response;

/**
 * 富友合作方聚合支付。
 *
 * 提供扫码预下单、JSAPI、付款码、查单、关单、撤销、退款和支付通知能力。
 * 插件负责区分支付宝、微信、银联产品及对应用户身份作用域；付款码支付会轮询最终状态，
 * 超时后主动撤销，避免把仍可能扣款的订单直接判为失败。
 */
class FuiouApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_BARCODE = 'barcode';

    private const ORDER_ALIPAY = 'ALIPAY';
    private const ORDER_WECHAT = 'WECHAT';
    private const ORDER_UNIONPAY = 'UNIONPAY';
    private const TRADE_ALIPAY_FWC = 'FWC';
    private const TRADE_WECHAT_MP = 'JSAPI';
    private const TRADE_WECHAT_MINI = 'LETPAY';
    private const BARCODE_QUERY_ATTEMPTS = 6;
    private const BARCODE_QUERY_INTERVAL = 5;

    private ?FuiouPayClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /**
     * 插件元信息。
     *
     * 配置表单由 getConfigSchema() 动态生成，以便按通道声明实际开通的聚合支付产品。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'fuiou_api',
        'name' => '富友合作方聚合支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.fuiou.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造富友支付插件。
     *
     * @param PayOrderRepository|null $payOrderRepository 支付单仓库；未注入时创建默认实例
     */
    public function __construct(?PayOrderRepository $payOrderRepository = null)
    {
        $this->payOrderRepository = $payOrderRepository ?? new PayOrderRepository();
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
            $this->inputField('institution_code', '机构号', true),
            $this->inputField('merchant_no', '富友商户号', true),
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户 RSA 私钥（PKCS#8/PKCS#1）',
                'value' => '',
                'props' => ['rows' => 6],
                'validate' => [['required' => true, 'message' => '商户 RSA 私钥不能为空']],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '富友 RSA 公钥',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [['required' => true, 'message' => '富友 RSA 公钥不能为空']],
            ],
            $this->inputField('order_prefix', '渠道订单号前缀（仅字母数字，最多 10 位）'),
            $this->inputField('terminal_serial_no', '付款码终端序列号'),
            $this->inputField('operator_id', '操作员号'),
            $this->inputField('wechat_mp_app_id', '微信公众号 AppID'),
            [
                'type' => 'password',
                'field' => 'wechat_mp_app_secret',
                'title' => '微信公众号 AppSecret',
                'value' => '',
            ],
            [
                'type' => 'switch',
                'field' => 'bindwxa',
                'title' => '允许绑定微信小程序',
                'value' => false,
            ],
            $this->inputField('wechat_mini_app_id', '微信小程序 AppID'),
            [
                'type' => 'password',
                'field' => 'wechat_mini_app_secret',
                'title' => '微信小程序 AppSecret',
                'value' => '',
            ],
            $this->inputField('wechat_mini_launch_path', '微信小程序支付承接路径'),
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
            [
                'type' => 'input-number',
                'field' => 'expire_minutes',
                'title' => '扫码订单有效分钟数',
                'value' => 5,
                'props' => ['min' => 1, 'max' => 60],
            ],
            $this->fuiouEnabledProductsField(),
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '富友测试环境',
                'value' => false,
                'props' => ['checkedText' => '测试', 'uncheckedText' => '生产'],
            ],
            $this->inputField('api_base_url', '自定义 HTTPS 网关（留空使用环境默认值）'),
        ];
    }

    /**
     * 声明当前订单的支付身份需求。
     *
     * 付款码自带付款人身份，不触发授权；支付宝、公众号和小程序只在对应产品与环境下
     * 声明精确应用作用域的身份要求。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；无需补充身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payment = $this->paymentPayload($order);
        if ($this->firstText($payment['auth_code'] ?? '') !== '') {
            return null;
        }

        $payType = $this->payTypeCode($order);
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));
        if ($payType === 'alipay'
            && $env === 'alipay'
            && in_array(self::PRODUCT_ALIPAY_JSAPI, $this->enabledProducts(), true)) {
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
                    'sandbox' => $this->configBool('sandbox'),
                ],
                'message' => '富友支付宝 JSAPI 需要先取得支付宝 user_id（buyer_id）',
            ];
        }

        if ($payType !== 'wxpay') {
            return null;
        }

        $mini = $this->isMiniIntent($payment);
        if ($mini) {
            if (!$this->configBool('bindwxa')) {
                throw new PaymentProductUnavailableException('富友通道未开启 bindwxa，不能使用 mini_openid', 40200);
            }
            if (!in_array(self::PRODUCT_WXPAY_MINI, $this->enabledProducts(), true)) {
                return null;
            }
            $this->assertPaymentAppScope($payment, $this->configText('wechat_mini_app_id'));
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
                'message' => '富友微信小程序支付需要当前小程序作用域的 mini_openid',
            ];
        }

        if ($env !== 'wechat' || !in_array(self::PRODUCT_WXPAY_MP, $this->enabledProducts(), true)) {
            return null;
        }
        $this->assertPaymentAppScope($payment, $this->configText('wechat_mp_app_id'));
        if ($this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '') !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => self::PRODUCT_WXPAY_MP,
            'channel_product' => self::PRODUCT_WXPAY_MP,
            'auth_type' => 'wechat_oauth',
            'identity_field' => 'openid',
            'app_id' => $this->configText('wechat_mp_app_id'),
            '_app_secret' => $this->configText('wechat_mp_app_secret'),
            'scope' => 'snsapi_base',
            'message' => '富友微信公众号支付需要当前公众号作用域的 openid',
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
        $payment = $this->paymentPayload($order);
        $payType = $this->payTypeCode($order);
        $authCode = $this->firstText($payment['auth_code'] ?? '');
        if ($authCode !== '') {
            $this->ensureProduct(self::PRODUCT_BARCODE);
            $this->assertBarcodeMatchesPayType($authCode, $payType);
        }
        if ($payType === 'wxpay' && $this->isMiniIntent($payment) && !$this->configBool('bindwxa')) {
            throw new PaymentProductUnavailableException('富友通道未开启 bindwxa，不能使用 mini_openid 或小程序支付', 40200);
        }
        if ($payType === 'wxpay' && $this->isMiniIntent($payment)) {
            // 小程序意图是调用方的显式选择，不能在产品未开通时静默降级成普通二维码。
            $this->ensureProduct(self::PRODUCT_WXPAY_MINI);
        }

        $order = $this->selectionOrder($order);
        $wxJsapiProduct = $this->isMiniIntent($payment)
            ? self::PRODUCT_WXPAY_MINI
            : self::PRODUCT_WXPAY_MP;
        $jsapiHandler = $wxJsapiProduct === self::PRODUCT_WXPAY_MINI ? 'mini' : 'jsapi';

        return $this->executeDirectPaymentProduct($order, [
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_BARCODE,
                    'wxpay' => self::PRODUCT_BARCODE,
                    'bank' => self::PRODUCT_BARCODE,
                ],
                'handler' => fn (): array => $this->barcodePay($order, $authCode),
            ],
            $jsapiHandler => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => $wxJsapiProduct,
                ],
                'handler' => fn (): array => $this->jsapiPay($order, $wxJsapiProduct),
            ],
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'bank' => self::PRODUCT_BANK_SCAN,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ], '富友');
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        $channelOrderNo = $this->channelOrderNo($order);
        try {
            $data = $this->client()->submit(FuiouPayClient::PATH_QUERY, [
                'order_type' => $this->orderType($this->payTypeCode($order)),
                'mchnt_order_no' => $channelOrderNo,
            ]);
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友查单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['result_code'] ?? '') !== FuiouPayClient::RESULT_SUCCESS) {
            throw new PaymentException(
                (string) ($data['result_msg'] ?? '富友查单结果未知，请稍后重试'),
                40200
            );
        }
        $this->assertResponseOrder($data, $channelOrderNo, $this->amountFromOrder($order, false));

        $channelStatus = strtoupper(trim((string) ($data['trans_stat'] ?? '')));
        $status = $this->tradeStatus($channelStatus);
        $paidAmount = null;
        if ($status === PaymentPluginStatusConstant::SUCCESS) {
            $amountText = $this->firstText($data['order_amt'] ?? '', $data['total_amount'] ?? '');
            if ($amountText === '' || !ctype_digit($amountText)) {
                throw new PaymentException('富友成功查单响应缺少支付金额', 40200);
            }
            $paidAmount = (int) $amountText;
        }

        return [
            'status' => $status,
            'pay_no' => trim((string) ($order['pay_no'] ?? '')),
            'paid_amount' => $paidAmount,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $this->firstText($data['transaction_id'] ?? '', $order['chan_trade_no'] ?? ''),
            'channel_status' => $channelStatus,
            'message' => (string) ($data['result_msg'] ?? $channelStatus),
            'paid_at' => $channelStatus === 'SUCCESS' ? $this->fuiouTime($data['txn_fin_ts'] ?? '') : null,
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
        if ($this->isBarcodeOrder($order)) {
            return $this->revokeBarcode($order);
        }

        $channelOrderNo = $this->channelOrderNo($order);
        try {
            $data = $this->client()->submit(FuiouPayClient::PATH_CLOSE, [
                'order_type' => $this->orderType($this->payTypeCode($order)),
                'mchnt_order_no' => $channelOrderNo,
                'sub_appid' => $this->orderSubAppId($order),
            ]);
        } catch (FuiouSdkException $e) {
            $this->throwMutationFailure($e, '富友关单失败');
        }
        $this->assertResponseOrder($data, $channelOrderNo, null);

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => trim((string) ($order['pay_no'] ?? '')),
            'message' => '富友普通支付订单已关闭',
            'chan_order_no' => $this->firstText($data['mchnt_order_no'] ?? '', $channelOrderNo),
            'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
        ];
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        $refundAmount = $this->positiveInteger($order['refund_amount'] ?? null, '富友退款金额必须是正整数分');
        $totalAmount = $this->positiveInteger(
            $order['amount'] ?? null,
            '富友原支付金额必须是正整数分'
        );
        if ($refundAmount > $totalAmount) {
            throw new PaymentDefinitiveException('富友退款金额不能大于原支付金额', 40200);
        }

        $channelOrderNo = $this->channelOrderNo($order);
        $channelRefundNo = $this->upstreamReference((string) ($order['refund_no'] ?? ''), 'R', true);
        try {
            $data = $this->client()->submit(FuiouPayClient::PATH_REFUND, [
                'mchnt_order_no' => $channelOrderNo,
                'refund_order_no' => $channelRefundNo,
                'order_type' => $this->orderType($this->payTypeCode($order)),
                'total_amt' => (string) $totalAmount,
                'refund_amt' => (string) $refundAmount,
                'operator_id' => $this->configText('operator_id'),
                'refund_desc' => mb_strcut(trim((string) ($order['refund_reason'] ?? $order['reason'] ?? '')), 0, 80, 'UTF-8'),
            ]);
        } catch (FuiouSdkException $e) {
            $this->throwMutationFailure($e, '富友退款失败');
        }

        $responseOrderNo = trim((string) ($data['mchnt_order_no'] ?? ''));
        if ($responseOrderNo !== $channelOrderNo) {
            throw new PaymentException('富友退款响应原订单号不一致', 40200);
        }
        $responseRefundNo = trim((string) ($data['refund_order_no'] ?? ''));
        if ($responseRefundNo !== $channelRefundNo) {
            throw new PaymentException('富友退款响应退款单号不一致', 40200);
        }
        if (isset($data['reserved_refund_amt']) && (int) $data['reserved_refund_amt'] !== $refundAmount) {
            throw new PaymentException('富友退款响应金额不一致', 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => trim((string) ($order['refund_no'] ?? '')),
            'pay_no' => trim((string) ($order['pay_no'] ?? '')),
            'message' => '富友退款受理成功',
            'chan_refund_no' => $responseRefundNo,
            'refund_amount' => $refundAmount,
        ];
    }

    /**
     * 解析并校验支付通知。
     *
     * 验签后核对商户、订单号、金额、支付产品和本地通道归属，只有明确成功状态才返回成功。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $encoded = trim((string) ($request->post('req') ?? ''));
        if ($encoded === '') {
            throw new PaymentException('富友回调缺少 req', 40200);
        }

        try {
            $payload = $this->client()->parseXml(urldecode($encoded));
            if (!$this->client()->verifyNotify($payload)) {
                throw new PaymentException('富友回调验签失败', 40200);
            }
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友回调报文无效：' . $e->getMessage(), 40200);
        }

        if ((string) ($payload['result_code'] ?? '') !== FuiouPayClient::RESULT_SUCCESS) {
            throw new PaymentException('富友回调不是明确支付成功状态', 40200, [
                'channel_status' => (string) ($payload['result_code'] ?? ''),
            ]);
        }
        $merchantNo = trim((string) ($payload['mchnt_cd'] ?? ''));
        if ($merchantNo === '' || !hash_equals($this->configText('merchant_no'), $merchantNo)) {
            throw new PaymentException('富友回调商户号不一致', 40200);
        }
        $institutionCode = trim((string) ($payload['ins_cd'] ?? ''));
        if ($institutionCode === '' || !hash_equals($this->configText('institution_code'), $institutionCode)) {
            throw new PaymentException('富友回调机构号不一致', 40200);
        }

        $channelOrderNo = trim((string) ($payload['mchnt_order_no'] ?? ''));
        $channelTradeNo = trim((string) ($payload['transaction_id'] ?? ''));
        if ($channelOrderNo === '' || $channelTradeNo === '') {
            throw new PaymentException('富友回调缺少商户订单号或渠道交易号', 40200);
        }
        $currency = strtoupper(trim((string) ($payload['curr_type'] ?? '')));
        if ($currency !== 'CNY') {
            throw new PaymentException('富友回调币种不是 CNY', 40200);
        }
        $amountText = trim((string) ($payload['order_amt'] ?? ''));
        if (!preg_match('/^[1-9]\d*$/', $amountText)) {
            throw new PaymentException('富友回调金额不是正整数分', 40200);
        }

        $payOrder = $this->payOrderRepository->findByReceiptChannelOrder(
            [(int) $this->getConfig('channel_id', 0)],
            $channelOrderNo
        );
        if (!$payOrder) {
            throw new PaymentException('富友回调未匹配到当前通道支付单', 40200);
        }
        $this->assertNotifyOrder($payOrder, $payload, (int) $amountText, $channelOrderNo);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => (string) $payOrder->pay_no,
            'paid_amount' => (int) $amountText,
            'message' => (string) ($payload['result_msg'] ?? '支付成功'),
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => FuiouPayClient::RESULT_SUCCESS,
            'paid_at' => $this->fuiouTime($payload['txn_fin_ts'] ?? ''),
        ];
    }

    /**
     * 返回渠道要求的支付通知成功应答。
     *
     * @return string|Response 成功应答
     */
    public function notifySuccess(): string|Response
    {
        return '1';
    }

    /**
     * 返回渠道要求的支付通知失败应答。
     *
     * @return string|Response 失败应答
     */
    public function notifyFail(): string|Response
    {
        return '0';
    }

    /**
     * 发起扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPay(array $order): array
    {
        $payType = $this->payTypeCode($order);
        $product = match ($payType) {
            'alipay' => self::PRODUCT_ALIPAY_SCAN,
            'wxpay' => self::PRODUCT_WXPAY_SCAN,
            'bank' => self::PRODUCT_BANK_SCAN,
            default => throw new PaymentException('富友不支持当前扫码支付方式', 40200),
        };
        $this->ensureProduct($product);
        $channelOrderNo = $this->channelOrderNo($order);

        try {
            $data = $this->client()->submit(
                FuiouPayClient::PATH_PRECREATE,
                $this->orderPayload($order, true) + [
                    'order_type' => $this->orderType($payType),
                    'reserved_expire_minute' => (string) $this->expireMinutes(),
                ]
            );
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友扫码下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = trim((string) ($data['qr_code'] ?? ''));
        if ($qrcode === '') {
            throw new PaymentException('富友扫码下单未返回二维码内容', 40200, $this->safeResponse($data));
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'qrcode',
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'preCreate',
            'pay_params' => ['qrcode' => $qrcode],
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $this->firstText($data['reserved_fy_order_no'] ?? '', $data['transaction_id'] ?? ''),
        ]);
    }

    /**
     * 发起 JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $wxProduct 已选择的微信 JSAPI 产品
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order, string $wxProduct): array
    {
        $payType = $this->payTypeCode($order);
        $payment = $this->paymentPayload($order);
        if ($payType === 'alipay') {
            $product = self::PRODUCT_ALIPAY_JSAPI;
            $tradeType = self::TRADE_ALIPAY_FWC;
            $userId = $this->firstText($payment['buyer_id'] ?? '');
            $appId = '';
            if ($userId === '') {
                throw new PaymentException('富友支付宝 JSAPI 缺少 buyer_id（支付宝 user_id）', 40200);
            }
        } elseif ($payType === 'wxpay') {
            $mini = $this->isMiniIntent($payment);
            $product = $mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP;
            if ($product !== $wxProduct) {
                throw new PaymentException('富友微信 JSAPI 产品选择不一致', 40200);
            }
            if ($mini && !$this->configBool('bindwxa')) {
                throw new PaymentProductUnavailableException('富友通道未开启 bindwxa，不能使用 mini_openid', 40200);
            }
            $tradeType = $mini ? self::TRADE_WECHAT_MINI : self::TRADE_WECHAT_MP;
            $userId = $mini
                ? $this->firstText($payment['mini_openid'] ?? '')
                : $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
            $appId = $mini ? $this->configText('wechat_mini_app_id') : $this->configText('wechat_mp_app_id');
            $this->assertPaymentAppScope($payment, $appId);
            if ($userId === '') {
                throw new PaymentException($mini
                    ? '富友微信小程序支付缺少 mini_openid'
                    : '富友微信公众号支付缺少 openid', 40200);
            }
        } else {
            throw new PaymentException('富友不支持当前 JSAPI 支付方式', 40200);
        }
        $this->ensureProduct($product);
        $channelOrderNo = $this->channelOrderNo($order);

        try {
            $data = $this->client()->submit(
                FuiouPayClient::PATH_WX_PRECREATE,
                $this->orderPayload($order, true) + [
                    'trade_type' => $tradeType,
                    'limit_pay' => '',
                    'product_id' => '',
                    'openid' => '',
                    'sub_openid' => $userId,
                    'sub_appid' => $appId,
                ]
            );
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友 JSAPI 下单失败：' . $e->getMessage(), 40200);
        }

        if ($tradeType === self::TRADE_ALIPAY_FWC) {
            $tradeNo = trim((string) ($data['reserved_transaction_id'] ?? ''));
            if ($tradeNo === '') {
                throw new PaymentException('富友支付宝 JSAPI 未返回 tradeNO', 40200, $this->safeResponse($data));
            }
            $params = ['tradeNO' => $tradeNo];
        } else {
            $params = [
                'appId' => (string) ($data['sdk_appid'] ?? ''),
                'timeStamp' => (string) ($data['sdk_timestamp'] ?? ''),
                'nonceStr' => (string) ($data['sdk_noncestr'] ?? ''),
                'package' => (string) ($data['sdk_package'] ?? ''),
                'signType' => (string) ($data['sdk_signtype'] ?? ''),
                'paySign' => (string) ($data['sdk_paysign'] ?? ''),
            ];
            if (in_array('', $params, true)) {
                throw new PaymentException('富友微信 JSAPI 返回拉起参数不完整', 40200, $this->safeResponse($data));
            }
            if ($params['appId'] !== $appId) {
                throw new PaymentException('富友微信 JSAPI 响应 AppID 与配置不一致', 40200);
            }
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'jsapi',
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'jsapi',
            'pay_params' => $params,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $this->firstText($data['reserved_transaction_id'] ?? '', $data['transaction_id'] ?? ''),
        ]);
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
        if ($authCode === '') {
            throw new PaymentException('富友付款码不能为空', 40200);
        }
        $payType = $this->payTypeCode($order);
        $orderType = $this->assertBarcodeMatchesPayType($authCode, $payType);

        try {
            $data = $this->client()->submit(
                FuiouPayClient::PATH_MICROPAY,
                $this->orderPayload($order, false) + [
                    'order_type' => $orderType,
                    'auth_code' => $authCode,
                    'sence' => '1',
                    'reserved_terminal_info' => json_encode([
                        'serial_num' => $this->configText('terminal_serial_no'),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友付款码下单失败：' . $e->getMessage(), 40200, [
                'channel_error_code' => $e->resultCode(),
            ]);
        }

        if ((string) ($data['result_code'] ?? '') === FuiouPayClient::RESULT_SUCCESS) {
            $this->assertResponseOrder($data, $this->channelOrderNo($order), $this->amountFromOrder($order));
            return $this->barcodeResult($order, $data);
        }

        return $this->waitBarcodePayment($order, $orderType, $data);
    }

    /**
     * 轮询付款码支付结果。
     *
     * 轮询仍无法确认时调用撤销接口；撤销失败表示渠道可能已扣款，必须保留为待人工确认。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $orderType 富友订单类型
     * @param array<string, mixed> $initial 首次付款码响应
     * @return array<string, mixed> 明确支付成功结果
     */
    private function waitBarcodePayment(array $order, string $orderType, array $initial): array
    {
        $lastSummary = $this->safeResponse($initial);
        for ($attempt = 0; $attempt < self::BARCODE_QUERY_ATTEMPTS; $attempt++) {
            $this->sleepSeconds(self::BARCODE_QUERY_INTERVAL);
            try {
                $query = $this->client()->submit(FuiouPayClient::PATH_QUERY, [
                    'order_type' => $orderType,
                    'mchnt_order_no' => $this->channelOrderNo($order),
                ]);
            } catch (FuiouSdkException $e) {
                $lastSummary = ['result_code' => $e->resultCode(), 'result_msg' => $e->getMessage()];
                continue;
            }
            $lastSummary = $this->safeResponse($query);
            if ((string) ($query['result_code'] ?? '') !== FuiouPayClient::RESULT_SUCCESS) {
                continue;
            }

            $this->assertResponseOrder($query, $this->channelOrderNo($order), $this->amountFromOrder($order, false));
            $status = strtoupper(trim((string) ($query['trans_stat'] ?? '')));
            if ($status === 'SUCCESS') {
                return $this->barcodeResult($order, $query);
            }
            if (in_array($status, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                throw new PaymentException('富友付款码支付已终止：' . $status, 40200, [
                    'channel_status' => $status,
                ]);
            }
        }

        $revoke = $this->revokeBarcode($order);
        if (!($revoke['success'] ?? false)) {
            throw new PaymentException('富友付款码等待超时且自动撤销失败，必须人工查单', 40200, [
                'last_query' => $lastSummary,
                'revoke_message' => (string) ($revoke['message'] ?? ''),
            ]);
        }

        throw new PaymentException('富友付款码等待超时，订单已撤销', 40200, [
            'channel_status' => 'REVOKED',
            'last_query' => $lastSummary,
        ]);
    }

    /**
     * 撤销付款码支付。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return array<string, mixed> 标准关单结果；撤销未确认时返回处理中
     */
    private function revokeBarcode(array $order): array
    {
        $channelOrderNo = $this->channelOrderNo($order);
        $cancelOrderNo = $this->upstreamReference('cancel|' . $channelOrderNo, 'C', false);
        $lastMessage = '富友付款码撤销失败';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $data = $this->client()->submit(FuiouPayClient::PATH_CANCEL, [
                    'mchnt_order_no' => $channelOrderNo,
                    'order_type' => $this->orderType($this->payTypeCode($order)),
                    'cancel_order_no' => $cancelOrderNo,
                    'operator_id' => $this->configText('operator_id'),
                ]);
            } catch (FuiouSdkException $e) {
                $lastMessage = $e->getMessage();
                break;
            }
            try {
                $this->assertResponseOrder($data, $channelOrderNo, null);
            } catch (PaymentException $e) {
                $lastMessage = $e->getMessage();
                break;
            }

            if (strtoupper(trim((string) ($data['recall'] ?? 'N'))) !== 'Y') {
                return [
                    'status' => PaymentPluginStatusConstant::CLOSED,
                    'pay_no' => trim((string) ($order['pay_no'] ?? '')),
                    'message' => '富友付款码订单已撤销',
                    'chan_order_no' => $channelOrderNo,
                    'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
                ];
            }
            $lastMessage = '富友要求重试撤销';
            if ($attempt < 2) {
                $this->sleepSeconds(1);
            }
        }

        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($order['pay_no'] ?? '')),
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
            'message' => $lastMessage,
        ];
    }

    /**
     * 构建付款码支付结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, mixed> $data 富友支付响应
     * @return array<string, mixed> 标准支付成功结果
     */
    private function barcodeResult(array $order, array $data): array
    {
        return $this->successfulPaymentResult($order, [
            'paid_amount' => (int) ($order['amount'] ?? 0),
            'pay_type' => $this->payTypeCode($order),
            'pay_product' => self::PRODUCT_BARCODE,
            'pay_action' => 'micropay',
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => trim((string) ($data['transaction_id'] ?? '')),
        ]);
    }

    /**
     * 构造各支付接口的业务字段。付款码明确不携带 notify_url。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $withNotify 是否携带异步通知地址
     * @return array<string, string> 富友业务请求字段
     */
    private function orderPayload(array $order, bool $withNotify): array
    {
        $clientIp = trim((string) ($order['client_ip'] ?? ''));
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new PaymentException('富友下单 client_ip 不合法', 40200);
        }
        $payload = [
            'order_amt' => (string) $this->amountFromOrder($order),
            'mchnt_order_no' => $this->channelOrderNo($order),
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => $this->goodsDescription((string) ($order['subject'] ?? '')),
            'goods_detail' => '',
            'term_ip' => $clientIp,
            'addn_inf' => '',
            'curr_type' => 'CNY',
            'goods_tag' => '',
        ];
        if ($withNotify) {
            $notifyUrl = trim((string) ($order['callback_url'] ?? ''));
            if ($notifyUrl === '' || filter_var($notifyUrl, FILTER_VALIDATE_URL) === false) {
                throw new PaymentException('富友预下单 callback_url 不合法', 40200);
            }
            $payload['notify_url'] = $notifyUrl;
        }

        return $payload;
    }

    /**
     * 获取渠道订单号。
     *
     * 银联订单号必须为纯数字；其他产品允许字母数字。原支付单号不满足协议时使用稳定哈希映射。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 富友渠道订单号
     */
    private function channelOrderNo(array $order): string
    {
        $existing = trim((string) ($order['chan_order_no'] ?? ''));
        if ($existing !== '') {
            if (!preg_match('/^[A-Za-z0-9]{5,30}$/', $existing)) {
                throw new PaymentException('已有富友渠道订单号不符合 5-30 位字母数字规则', 40200);
            }
            return $existing;
        }

        $payNo = trim((string) ($order['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new PaymentException('富友订单缺少 pay_no', 40200);
        }
        $seed = $this->configText('order_prefix') . $payNo;
        if ($this->payTypeCode($order) === 'bank') {
            return $this->numericReference($seed);
        }
        if (preg_match('/^[A-Za-z0-9]{5,30}$/', $seed)) {
            return $seed;
        }

        return 'F' . strtoupper(substr(hash('sha256', $seed), 0, 29));
    }

    /**
     * 获取支付方式编码。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 平台支付方式编码
     */
    private function payTypeCode(array $order): string
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentException('富友不支持当前支付方式', 40200, ['pay_type' => $payType]);
        }

        return $payType;
    }

    /**
     * 将平台支付方式映射为富友订单类型。
     *
     * @param string $payType 平台支付方式编码
     * @return string 富友订单类型
     */
    private function orderType(string $payType): string
    {
        return match ($payType) {
            'alipay' => self::ORDER_ALIPAY,
            'wxpay' => self::ORDER_WECHAT,
            'bank' => self::ORDER_UNIONPAY,
            default => throw new PaymentException('富友支付方式无法映射 order_type', 40200),
        };
    }

    /**
     * 将富友交易状态映射为平台支付状态。
     *
     * @param string $status 富友交易状态
     * @return string 平台支付状态
     */
    private function tradeStatus(string $status): string
    {
        return match ($status) {
            'SUCCESS', 'REFUND' => PaymentPluginStatusConstant::SUCCESS,
            'PAYERROR' => PaymentPluginStatusConstant::FAILED,
            'CLOSED', 'REVOKED' => PaymentPluginStatusConstant::CLOSED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 构建支付产品选择参数。
     *
     * bindwxa 小程序仍在微信环境内选择 JSAPI，但保留小程序产品意图供处理器判断。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 产品选择参数
     */
    private function selectionOrder(array $order): array
    {
        if ($this->payTypeCode($order) === 'wxpay'
            && $this->isMiniIntent($this->paymentPayload($order))
            && $this->configBool('bindwxa')) {
            $order['_env'] = 'wechat';
        }

        return $order;
    }

    /**
     * 判断是否为付款码订单。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return bool 是否为付款码订单
     */
    private function isBarcodeOrder(array $order): bool
    {
        return trim((string) ($order['pay_product'] ?? '')) === self::PRODUCT_BARCODE;
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

        return $this->firstText($payment['mini_openid'] ?? '') !== ''
            || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * 获取支付扩展参数。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return array<string, mixed> 支付扩展参数
     */
    private function paymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? $order['ext_json'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 校验支付身份所属应用。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $expectedAppId 当前产品配置的应用 ID
     * @return void
     */
    private function assertPaymentAppScope(array $payment, string $expectedAppId): void
    {
        $provided = $this->firstText($payment['sub_appid'] ?? '', $payment['app_id'] ?? '');
        if ($provided !== '' && !hash_equals($expectedAppId, $provided)) {
            throw new PaymentException('富友微信身份 AppID 与当前产品配置不一致', 40200);
        }
    }

    /**
     * 校验付款码类型与所选支付方式一致。
     *
     * @param string $authCode 付款码
     * @param string $payType 平台支付方式编码
     * @return string 富友订单类型
     */
    private function assertBarcodeMatchesPayType(string $authCode, string $payType): string
    {
        $detected = $this->detectBarcodeOrderType($authCode);
        $expected = $this->orderType($payType);
        if ($detected !== $expected) {
            throw new PaymentException('付款码类型与所选支付方式不一致', 40200, [
                'selected_order_type' => $expected,
                'detected_order_type' => $detected,
            ]);
        }

        return $detected;
    }

    /**
     * 根据官方付款码号段识别富友订单类型。
     *
     * @param string $authCode 付款码
     * @return string 富友订单类型
     */
    private function detectBarcodeOrderType(string $authCode): string
    {
        $authCode = trim($authCode);
        if (preg_match('/^1[0-5]\d{16}$/', $authCode)) {
            return self::ORDER_WECHAT;
        }
        if (preg_match('/^(?:2[5-9]|30)\d{14,22}$/', $authCode)) {
            return self::ORDER_ALIPAY;
        }
        if (preg_match('/^62\d{14,18}$/', $authCode)) {
            return self::ORDER_UNIONPAY;
        }

        throw new PaymentException('无法识别付款码类型，请确认是支付宝、微信或银联付款码', 40200);
    }

    /**
     * 校验渠道响应订单。
     *
     * @param array<string, mixed> $data 富友响应
     * @param string $channelOrderNo 预期渠道订单号
     * @param int|null $expectedAmount 预期金额，单位分；null 表示不校验金额
     * @return void
     */
    private function assertResponseOrder(array $data, string $channelOrderNo, ?int $expectedAmount): void
    {
        $responseOrderNo = $this->firstText(
            $data['mchnt_order_no'] ?? '',
            $data['reserved_mchnt_order_no'] ?? ''
        );
        if ($responseOrderNo === '' || $responseOrderNo !== $channelOrderNo) {
            throw new PaymentException('富友响应商户订单号不一致', 40200);
        }
        $merchantNo = trim((string) ($data['mchnt_cd'] ?? ''));
        if ($merchantNo !== '' && !hash_equals($this->configText('merchant_no'), $merchantNo)) {
            throw new PaymentException('富友响应商户号不一致', 40200);
        }
        $amount = $this->firstText($data['order_amt'] ?? '', $data['total_amount'] ?? '');
        if ($expectedAmount !== null && ($amount === '' || !ctype_digit($amount) || (int) $amount !== $expectedAmount)) {
            throw new PaymentException('富友响应金额与支付单不一致', 40200);
        }
    }

    /**
     * 校验通知与本地支付单是否一致。
     *
     * @param PayOrder $payOrder 本地支付单
     * @param array<string, mixed> $payload 富友通知载荷
     * @param int $amount 通知金额，单位分
     * @param string $channelOrderNo 通知渠道订单号
     * @return void
     */
    private function assertNotifyOrder(PayOrder $payOrder, array $payload, int $amount, string $channelOrderNo): void
    {
        if ((string) ($payOrder->channel_order_no ?? '') !== $channelOrderNo) {
            throw new PaymentException('富友回调渠道订单号与支付单不一致', 40200);
        }
        if ((int) $payOrder->pay_amount !== $amount) {
            throw new PaymentException('富友回调金额与支付单不一致', 40200);
        }
        $extra = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extra['payment_context'] ?? []);
        $presentation = (array) ($extra['presentation'] ?? []);
        $expectedType = $this->orderType((string) ($context['pay_type'] ?? $presentation['pay_type'] ?? ''));
        $callbackType = strtoupper(trim((string) ($payload['order_type'] ?? '')));
        if ($callbackType !== '' && $callbackType !== $expectedType) {
            throw new PaymentException('富友回调 order_type 与支付单不一致', 40200);
        }
    }

    /**
     * 获取订单子应用 ID。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 微信子应用 ID；非微信 JSAPI 产品返回空字符串
     */
    private function orderSubAppId(array $order): string
    {
        $product = trim((string) ($order['pay_product'] ?? ''));

        return match ($product) {
            self::PRODUCT_WXPAY_MP => $this->configText('wechat_mp_app_id'),
            self::PRODUCT_WXPAY_MINI => $this->configText('wechat_mini_app_id'),
            default => '',
        };
    }

    /**
     * 读取订单金额。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @param bool $required 金额是否必填
     * @return int|null 订单金额，单位分
     */
    private function amountFromOrder(array $order, bool $required = true): ?int
    {
        $value = $order['amount'] ?? null;
        if ($value === null && !$required) {
            return null;
        }

        return $this->positiveInteger($value, '富友支付金额必须是正整数分');
    }

    /**
     * 读取正整数协议值。
     *
     * @param mixed $value 待校验值
     * @param string $message 校验失败消息
     * @return int 正整数
     */
    private function positiveInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value)) {
            return (int) $value;
        }

        throw new PaymentDefinitiveException($message, 40200);
    }

    /**
     * 按富友 GBK 字节限制截取商品描述。
     *
     * @param string $subject 平台订单标题
     * @return string 不超过 127 个 GBK 字节的商品描述
     */
    private function goodsDescription(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new PaymentException('富友商品描述不能为空', 40200);
        }
        $gbk = mb_convert_encoding($subject, 'GBK', 'UTF-8');
        $gbk = mb_strcut($gbk, 0, 127, 'GBK');

        return mb_convert_encoding($gbk, 'UTF-8', 'GBK');
    }

    /**
     * 生成符合富友长度与字符集限制的业务单号。
     *
     * @param string $source 原始业务标识
     * @param string $prefix 映射单号前缀
     * @param bool $preserveValid 是否保留已符合协议的原值
     * @return string 富友业务单号
     */
    private function upstreamReference(string $source, string $prefix, bool $preserveValid): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new PaymentException('富友业务单号不能为空', 40200);
        }
        if ($preserveValid && preg_match('/^[A-Za-z0-9_]{5,30}$/', $source)) {
            return $source;
        }

        return $prefix . strtoupper(substr(hash('sha256', $source), 0, 29));
    }

    /**
     * 将业务标识稳定映射为 30 位数字单号。
     *
     * 银联产品只接受纯数字订单号，不能直接复用可能包含字母的平台支付单号。
     *
     * @param string $source 原始业务标识
     * @return string 30 位数字单号
     */
    private function numericReference(string $source): string
    {
        $hex = hash('sha256', $source);
        $digits = '';
        for ($index = 0; $index < 30; $index++) {
            $digits .= (string) (hexdec($hex[$index]) % 10);
        }

        return $digits;
    }

    /**
     * 解析富友 YmdHis 时间。
     *
     * @param mixed $value 渠道时间
     * @return string|null 平台时间；格式不合法时返回 null
     */
    private function fuiouTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{14}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!YmdHis', $value);

        return $date ? $date->format('Y-m-d H:i:s') : null;
    }

    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentProductUnavailableException('当前富友通道未开启该支付产品', 40200, ['product' => $product]);
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

        return is_array($products)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $products
            ))))
            : [];
    }

    /**
     * 校验通道密钥、产品及产品关联配置。
     *
     * @return void
     */
    private function validateConfiguration(): void
    {
        foreach (['institution_code', 'merchant_no', 'merchant_private_key', 'platform_public_key'] as $key) {
            if ($this->configText($key) === '') {
                throw new PaymentException('富友配置缺少 ' . $key, 40200);
            }
        }
        $prefix = $this->configText('order_prefix');
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9]{1,10}$/', $prefix)) {
            throw new PaymentException('富友订单号前缀只能是最多 10 位字母数字', 40200);
        }
        $customGateway = $this->configText('api_base_url');
        if ($customGateway !== '' && (!str_starts_with(strtolower($customGateway), 'https://')
            || filter_var($customGateway, FILTER_VALIDATE_URL) === false)) {
            throw new PaymentException('富友自定义网关必须是有效 HTTPS 地址', 40200);
        }
        $supported = [
            self::PRODUCT_ALIPAY_SCAN,
            self::PRODUCT_ALIPAY_JSAPI,
            self::PRODUCT_WXPAY_SCAN,
            self::PRODUCT_WXPAY_MP,
            self::PRODUCT_WXPAY_MINI,
            self::PRODUCT_BANK_SCAN,
            self::PRODUCT_BARCODE,
        ];
        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, $supported) !== []) {
            throw new PaymentException('富友已开通产品配置为空或包含未知产品', 40200);
        }
        if (in_array(self::PRODUCT_BARCODE, $enabled, true) && $this->configText('terminal_serial_no') === '') {
            throw new PaymentException('富友付款码产品必须配置终端序列号', 40200);
        }
        if (in_array(self::PRODUCT_WXPAY_MP, $enabled, true)
            && ($this->configText('wechat_mp_app_id') === '' || $this->configText('wechat_mp_app_secret') === '')) {
            throw new PaymentException('富友微信公众号产品必须配置 AppID 与 AppSecret', 40200);
        }
        if (in_array(self::PRODUCT_WXPAY_MINI, $enabled, true)) {
            if (!$this->configBool('bindwxa')) {
                throw new PaymentException('富友微信小程序产品只有 bindwxa=true 时才能启用', 40200);
            }
            if ($this->configText('wechat_mini_app_id') === '' || $this->configText('wechat_mini_app_secret') === '') {
                throw new PaymentException('富友微信小程序产品必须配置 AppID 与 AppSecret', 40200);
            }
        }
        if (in_array(self::PRODUCT_ALIPAY_JSAPI, $enabled, true)) {
            foreach (['alipay_oauth_app_id', 'alipay_oauth_private_key', 'alipay_oauth_public_key'] as $key) {
                if ($this->configText($key) === '') {
                    throw new PaymentException('富友支付宝 JSAPI 身份流程缺少 ' . $key, 40200);
                }
            }
        }
        $this->expireMinutes();
    }

    private function expireMinutes(): int
    {
        $value = $this->getConfig('expire_minutes', 5);
        $minutes = is_numeric($value) ? (int) $value : 0;
        if ($minutes < 1 || $minutes > 60) {
            throw new PaymentException('富友扫码订单有效时间必须为 1-60 分钟', 40200);
        }

        return $minutes;
    }

    /**
     * 将富友变更类请求异常转换为确定失败或结果不确定异常。
     *
     * 渠道返回 result_code 表示已有明确业务结论；没有结果码时请求结果无法确认。
     *
     * @param FuiouSdkException $e SDK 异常
     * @param string $message 业务错误消息
     * @return never
     */
    private function throwMutationFailure(FuiouSdkException $e, string $message): never
    {
        $exception = $e->resultCode() !== ''
            ? PaymentDefinitiveException::class
            : PaymentUncertainException::class;

        throw new $exception($message . '：' . $e->getMessage(), 40200, [
            'channel_error_code' => $e->resultCode(),
        ]);
    }

    /**
     * 获取当前通道的富友客户端。
     *
     * @return FuiouPayClient
     */
    private function client(): FuiouPayClient
    {
        if ($this->client === null) {
            $this->client = new FuiouPayClient([
                'institution_code' => $this->configText('institution_code'),
                'merchant_no' => $this->configText('merchant_no'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'sandbox' => $this->configBool('sandbox'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    private function sleepSeconds(int $seconds): void
    {
        $callback = $this->getConfig('_sleep_callback');
        if (is_callable($callback)) {
            $callback($seconds);
            return;
        }

        sleep(max(0, $seconds));
    }

    /**
     * 生成脱敏响应摘要。
     *
     * @param array<string, mixed> $payload 富友响应
     * @return array<string, mixed> 已剔除签名和身份字段的响应摘要
     */
    private function safeResponse(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'result_code',
            'result_msg',
            'mchnt_cd',
            'mchnt_order_no',
            'order_type',
            'trans_stat',
            'reserved_trace_no',
        ]));
    }

    /**
     * 构建文本输入配置字段。
     *
     * @param string $field 配置字段名
     * @param string $title 配置项标题
     * @param bool $required 是否必填
     * @return array<string, mixed> 文本输入配置
     */
    private function inputField(string $field, string $title, bool $required = false): array
    {
        $result = ['type' => 'input', 'field' => $field, 'title' => $title, 'value' => ''];
        if ($required) {
            $result['validate'] = [['required' => true, 'message' => $title . '不能为空']];
        }

        return $result;
    }

    /**
     * 构建富友已开通产品配置字段。
     *
     * @return array<string, mixed>
     */
    private function fuiouEnabledProductsField(): array
    {
        $field = $this->directPaymentEnabledProductsField([
            self::PRODUCT_ALIPAY_SCAN => '支付宝扫码',
            self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
            self::PRODUCT_WXPAY_SCAN => '微信扫码',
            self::PRODUCT_WXPAY_MP => '微信公众号 JSAPI',
            self::PRODUCT_WXPAY_MINI => '微信小程序',
            self::PRODUCT_BANK_SCAN => '银联云闪付扫码',
            self::PRODUCT_BARCODE => '付款码被扫（支付宝/微信/银联）',
        ]);
        $field['value'] = [
            self::PRODUCT_ALIPAY_SCAN,
            self::PRODUCT_WXPAY_SCAN,
            self::PRODUCT_BANK_SCAN,
        ];

        return $field;
    }

    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    private function configBool(string $key): bool
    {
        return filter_var($this->getConfig($key, false), FILTER_VALIDATE_BOOL);
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
