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
use app\common\sdk\kuaiqian\KuaiqianClient;
use app\common\sdk\kuaiqian\KuaiqianSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use DateTimeImmutable;
use support\Request;
use support\Response;

/**
 * 快钱人民币网关、移动 H5 和微信公众号表单支付插件。
 *
 * 使用商户 PFX 私钥证书签名、平台公钥证书验签，并把私有证书对象键约束在平台证书目录。
 * 当前 profile 未完成加密扫码、主动查单、关单与退款闭环，因此不会发送缺乏协议依据的请求。
 */
class KuaiqianApiPayment extends BasePayment implements
    PaymentInterface,
    PayPluginInterface,
    PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_CODE_27_3 = '27-3';
    private const PRODUCT_CODE_21 = '21';
    private const PRODUCT_CODE_26_1 = '26-1';
    private const PRODUCT_CODE_26_2 = '26-2';
    private const PRODUCT_CODE_00 = '00';
    private const PRODUCT_CODE_10 = '10';

    /**
     * @var array<int, string>
     */
    private const FORM_PRODUCTS = [
        self::PRODUCT_CODE_27_3,
        self::PRODUCT_CODE_21,
        self::PRODUCT_CODE_26_1,
        self::PRODUCT_CODE_26_2,
        self::PRODUCT_CODE_00,
        self::PRODUCT_CODE_10,
    ];

    private ?KuaiqianClient $client = null;

    private PayOrderRepository $payOrderRepository;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'kuaiqian_api',
        'name' => '快钱支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.99bill.com/',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 构造快钱支付插件。
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
        $this->validateConfiguration();
    }

    /**
     * 获取插件配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        $products = $this->directPaymentEnabledProductsField([
            self::PRODUCT_CODE_27_3 => '支付宝 WAP（移动/H5）',
            self::PRODUCT_CODE_21 => '银行卡快捷（移动/H5）',
            self::PRODUCT_CODE_26_1 => '微信公众号支付',
            self::PRODUCT_CODE_26_2 => '微信 WAP（移动/H5）',
            self::PRODUCT_CODE_00 => '通用支付方式列表（PC/移动）',
            self::PRODUCT_CODE_10 => '银行卡网银（PC）',
        ]);
        $products['value'] = [];

        return [
            $this->requiredInput('account_id', '快钱 11 位商户号'),
            [
                'type' => 'password',
                'field' => 'merchant_cert_password',
                'title' => '商户 PFX 证书密码',
                'value' => '',
                'validate' => [['required' => true, 'message' => '商户 PFX 证书密码不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'platform_cert_path',
                'title' => '快钱平台公钥证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [['required' => true, 'message' => '快钱平台公钥证书不能为空']],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_key_path',
                'title' => '商户 PFX 私钥证书',
                'value' => '',
                'props' => $this->uploadProps('.pfx,.p12'),
                'validate' => [['required' => true, 'message' => '商户 PFX 私钥证书不能为空']],
            ],
            ['type' => 'input', 'field' => 'wechat_mp_app_id', 'title' => '微信公众号 AppID', 'value' => ''],
            ['type' => 'password', 'field' => 'wechat_mp_app_secret', 'title' => '微信公众号 AppSecret', 'value' => ''],
            $products,
        ];
    }

    /**
     * 声明支付所需的用户身份。
     *
     * 当前只支持固定公众号 AppID 作用域的 OpenID；任何小程序意图都会明确拒绝。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份要求；无需补充身份时返回 null
     */
    public function identityRequirement(array $order): ?array
    {
        $payType = $this->payType($order);
        $payment = $this->paymentPayload($order);
        if ($payType !== 'wxpay') {
            return null;
        }
        $this->assertNoMiniProgramIdentity($payment);

        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));
        if ($env !== 'wechat' || !in_array(self::PRODUCT_CODE_26_1, $this->enabledProducts(), true)) {
            return null;
        }

        $appId = $this->configText('wechat_mp_app_id');
        $this->assertWechatAppScope($payment, $appId);
        if ($this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '') !== '') {
            return null;
        }

        return [
            'provider' => 'wxpay',
            'product' => self::PRODUCT_CODE_26_1,
            'channel_product' => self::PRODUCT_CODE_26_1,
            'auth_type' => 'wechat_oauth',
            'identity_field' => 'openid',
            'identity_aliases' => ['sub_openid'],
            'app_id' => $appId,
            '_app_secret' => $this->configText('wechat_mp_app_secret'),
            'scope' => 'snsapi_base',
            'message' => '快钱微信公众号支付需要当前固定 AppID 作用域的 openid',
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
        $this->assertNoMiniProgramIdentity($payment);
        if ($this->firstText($payment['auth_code'] ?? '') !== ''
            || strtolower($this->firstText($payment['method'] ?? '')) === 'qrcode') {
            throw new UnsupportedPaymentOperationException('快钱加密扫码/当面付 profile 尚未完成证书闭环', 40200);
        }

        $payType = $this->payType($order);

        return $this->executeDirectPaymentProduct($order, [
            'jsapi' => [
                'products' => ['wxpay' => self::PRODUCT_CODE_26_1],
                'handler' => fn (): array => $this->formPay($order, self::PRODUCT_CODE_26_1),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_27_3,
                    'wxpay' => self::PRODUCT_CODE_26_2,
                    'bank' => self::PRODUCT_CODE_21,
                ],
                'handler' => fn (): array => $this->formPay($order, match ($payType) {
                    'alipay' => self::PRODUCT_CODE_27_3,
                    'wxpay' => self::PRODUCT_CODE_26_2,
                    default => self::PRODUCT_CODE_21,
                }),
            ],
            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_27_3,
                    'wxpay' => self::PRODUCT_CODE_26_2,
                    'bank' => self::PRODUCT_CODE_00,
                ],
                'handler' => fn (): array => $this->formPay($order, match ($payType) {
                    'alipay' => self::PRODUCT_CODE_27_3,
                    'wxpay' => self::PRODUCT_CODE_26_2,
                    default => self::PRODUCT_CODE_00,
                }),
            ],
            'web' => [
                'products' => ['bank' => self::PRODUCT_CODE_10],
                'handler' => fn (): array => $this->formPay($order, self::PRODUCT_CODE_10),
            ],
        ], '快钱');
    }

    /**
     * 发起表单支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 快钱支付产品编码
     * @return array<string, mixed> 标准支付结果
     */
    private function formPay(array $order, string $product): array
    {
        $payType = $this->payType($order);
        $this->assertProductMatchesPayType($product, $payType);
        $env = strtolower(trim((string) ($order['_env'] ?? 'pc')));
        $mobile = $product !== self::PRODUCT_CODE_10
            && !($product === self::PRODUCT_CODE_00 && $env === 'pc');
        if (($product !== self::PRODUCT_CODE_00 && $mobile && $env === 'pc')
            || ($product === self::PRODUCT_CODE_10 && $env !== 'pc')) {
            throw new UnsupportedPaymentOperationException('快钱当前产品不适用于该终端环境', 40200);
        }

        $payNo = $this->gatewayOrderNo($order['pay_no'] ?? '');
        $amount = $this->positiveCents($order['amount'] ?? null, '快钱下单金额必须是正整数分');
        $callbackUrl = $this->absoluteUrl($order['callback_url'] ?? '', '快钱回调地址');
        $returnUrl = $this->optionalAbsoluteUrl($order['return_url'] ?? '', '快钱返回地址');
        $clientIp = $this->firstText($order['client_ip'] ?? '');
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new PaymentDefinitiveException('快钱终端 IP 格式无效', 40200);
        }

        $signedParams = [
            'inputCharset' => '1',
            'pageUrl' => $returnUrl,
            'bgUrl' => $callbackUrl,
            'version' => $mobile ? 'mobile1.0' : 'v2.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => $this->merchantAccountId(),
            'orderId' => $payNo,
            'orderAmount' => (string) $amount,
            'orderTime' => date('YmdHis'),
            'productName' => mb_strcut($this->firstText($order['subject'] ?? 'MPAY 订单') ?: 'MPAY 订单', 0, 256, 'UTF-8'),
            'payType' => $product,
        ];
        if ($product === self::PRODUCT_CODE_26_1) {
            $payment = $this->paymentPayload($order);
            $this->assertWechatAppScope($payment, $this->configText('wechat_mp_app_id'));
            $openId = $this->firstText($payment['sub_openid'] ?? '', $payment['openid'] ?? '');
            if ($openId === '') {
                throw new PaymentDefinitiveException('快钱公众号身份不能为空', 40200);
            }
            $signedParams['aggregatePay'] = '26-1:[appId=' . $this->configText('wechat_mp_app_id')
                . ',openId=' . $openId . ',limitPay=0]';
        }

        $unsignedParams = ['terminalIp' => $clientIp];
        if ($payType === 'bank') {
            $unsignedParams['tdpformName'] = 'MPAY';
        }

        try {
            $html = $this->client()->formHtml(
                $mobile ? KuaiqianClient::MOBILE_GATEWAY : KuaiqianClient::BANK_GATEWAY,
                $signedParams,
                $unsignedParams
            );
        } catch (KuaiqianSdkException $e) {
            throw new PaymentDefinitiveException('快钱证书或表单签名失败：' . $e->getMessage(), 40200);
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'html',
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $mobile ? 'mobilegateway' : 'gateway',
            'pay_params' => ['html' => $html],
            'chan_order_no' => $payNo,
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('快钱加密查单 profile 尚未完成双向 TLS 和状态联调', 40200);
    }

    /**
     * 关闭支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('快钱加密关单/撤销 profile 尚未完成双向 TLS 和状态联调', 40200);
    }

    /**
     * 发起退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('快钱加密退款 profile 尚未完成双向 TLS 和状态联调', 40200);
    }

    /**
     * 校验并解析支付通知。
     *
     * 验签后强制关联本地通道、商户、产品快照、网关版本、金额、订单号和渠道流水。
     *
     * @param Request $request 支付通知请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $request->get();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new PaymentException('快钱回调参数格式无效', 40200);
        }
        foreach ($payload as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new PaymentException('快钱回调字段必须是标量', 40200);
            }
        }

        try {
            if (!$this->client()->verifyNotify($payload)) {
                throw new PaymentException('快钱回调验签失败', 40200);
            }
        } catch (KuaiqianSdkException $e) {
            throw new PaymentException('快钱回调证书或签名无效：' . $e->getMessage(), 40200);
        }

        $payNo = $this->gatewayOrderNo($payload['orderId'] ?? '');
        $payOrder = $this->payOrderRepository->findByPayNo($payNo, [
            'pay_no', 'pay_amount', 'channel_id', 'channel_order_no', 'channel_trade_no', 'ext_json',
        ]);
        if (!$payOrder instanceof PayOrder) {
            throw new PaymentException('快钱回调支付单不存在', 40200, ['pay_no' => $payNo]);
        }
        $channelId = (int) $this->getConfig('channel_id', 0);
        if ($channelId <= 0 || (int) $payOrder->channel_id !== $channelId) {
            throw new PaymentException('快钱回调支付单不属于当前通道', 40200, ['pay_no' => $payNo]);
        }

        $this->assertNotifyFixedFields($payload, $payNo);
        $this->assertNotifyProduct($payload, $payOrder);
        $amount = $this->positiveCents($payload['orderAmount'] ?? null, '快钱回调金额格式无效');
        if ($amount !== (int) $payOrder->pay_amount) {
            throw new PaymentException('快钱回调金额与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }
        if (($payload['payAmount'] ?? '') !== '') {
            $this->nonNegativeCents($payload['payAmount'], '快钱回调实际支付金额格式无效');
        }

        $dealId = $this->requiredText($payload['dealId'] ?? '', '快钱回调缺少 dealId');
        if (strlen($dealId) > 30 || preg_match('/^[A-Za-z0-9_-]+$/D', $dealId) !== 1) {
            throw new PaymentException('快钱回调 dealId 格式无效', 40200, ['pay_no' => $payNo]);
        }
        $storedOrderNo = $this->firstText($payOrder->channel_order_no ?? '');
        if ($storedOrderNo !== '' && !hash_equals($storedOrderNo, $payNo)) {
            throw new PaymentException('快钱回调渠道订单号与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }
        $storedTradeNo = $this->firstText($payOrder->channel_trade_no ?? '');
        if ($storedTradeNo !== '' && !hash_equals($storedTradeNo, $dealId)) {
            throw new PaymentException('快钱回调 dealId 与支付单不匹配', 40200, ['pay_no' => $payNo]);
        }

        $this->requiredGatewayTime($payload['orderTime'] ?? '', '快钱回调 orderTime 格式无效');
        $paidAt = $this->requiredGatewayTime($payload['dealTime'] ?? '', '快钱回调 dealTime 格式无效');
        $payResult = $this->requiredText($payload['payResult'] ?? '', '快钱回调缺少 payResult');
        $status = match ($payResult) {
            '10' => PaymentPluginStatusConstant::SUCCESS,
            '11' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS ? $amount : null,
            'message' => $this->notifyMessage($payResult, $payload['errCode'] ?? ''),
            'chan_order_no' => $payNo,
            'chan_trade_no' => $dealId,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? $paidAt : null,
        ];
    }

    /**
     * 返回渠道要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '<result>1</result>';
    }

    /**
     * 返回渠道要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '<result>0</result>';
    }

    /**
     * 校验通知中的固定协议字段。
     *
     * @param array<string, mixed> $payload 快钱通知字段
     * @param string $payNo 平台支付单号
     * @return void
     */
    private function assertNotifyFixedFields(array $payload, string $payNo): void
    {
        $merchantAcctId = $this->requiredText($payload['merchantAcctId'] ?? '', '快钱回调缺少 merchantAcctId');
        if (!hash_equals($this->merchantAccountId(), $merchantAcctId)) {
            throw new PaymentException('快钱回调商户号不匹配', 40200, ['pay_no' => $payNo]);
        }
        foreach (['language' => '1', 'signType' => '4'] as $field => $expected) {
            if (!hash_equals($expected, $this->requiredText($payload[$field] ?? '', '快钱回调缺少 ' . $field))) {
                throw new PaymentException('快钱回调固定字段 ' . $field . ' 不匹配', 40200, ['pay_no' => $payNo]);
            }
        }
    }

    /**
     * 校验通知支付产品。
     *
     * @param array<string, mixed> $payload 快钱通知字段
     * @param PayOrder $payOrder 本地支付单
     * @return string 下单产品快照
     */
    private function assertNotifyProduct(array $payload, PayOrder $payOrder): string
    {
        $ext = $payOrder->ext_json ?? [];
        if (is_string($ext)) {
            $decoded = json_decode($ext, true);
            $ext = is_array($decoded) ? $decoded : [];
        }
        $context = is_array($ext) ? (array) ($ext['payment_context'] ?? []) : [];
        $product = $this->firstText($context['pay_product'] ?? '');
        if (!in_array($product, self::FORM_PRODUCTS, true)) {
            throw new PaymentException('快钱回调无法识别支付单产品快照', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('快钱回调产品未在当前通道启用', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
        $notifiedProduct = $this->requiredText($payload['payType'] ?? '', '快钱回调缺少 payType');
        if (!hash_equals($product, $notifiedProduct)) {
            throw new PaymentException('快钱回调产品与支付单快照不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }
        $payAction = $this->firstText($context['pay_action'] ?? '');
        $expectedVersion = match ($product) {
            self::PRODUCT_CODE_10 => 'v2.0',
            self::PRODUCT_CODE_00 => match ($payAction) {
                'gateway' => 'v2.0',
                'mobilegateway' => 'mobile1.0',
                default => throw new PaymentException('快钱回调缺少有效支付入口快照', 40200, ['pay_no' => (string) $payOrder->pay_no]),
            },
            default => 'mobile1.0',
        };
        $version = $this->requiredText($payload['version'] ?? '', '快钱回调缺少 version');
        if (!hash_equals($expectedVersion, $version)) {
            throw new PaymentException('快钱回调网关版本与产品不匹配', 40200, ['pay_no' => (string) $payOrder->pay_no]);
        }

        return $product;
    }

    /**
     * 获取当前通道的快钱客户端。
     *
     * @return KuaiqianClient
     */
    private function client(): KuaiqianClient
    {
        if ($this->client === null) {
            try {
                $this->client = new KuaiqianClient([
                    'merchant_cert_password' => $this->configText('merchant_cert_password'),
                    'platform_cert_path' => $this->privateCertificatePath(
                        $this->configText('platform_cert_path'),
                        ['cer', 'crt', 'pem']
                    ),
                    'merchant_key_path' => $this->privateCertificatePath(
                        $this->configText('merchant_key_path'),
                        ['pfx', 'p12']
                    ),
                ]);
            } catch (KuaiqianSdkException $e) {
                throw new PaymentDefinitiveException('快钱证书初始化失败：' . $e->getMessage(), 40200);
            }
        }

        return $this->client;
    }

    /**
     * 解析私有证书文件路径。
     *
     * 对象键通过语法校验后仍使用 realpath 校验最终路径，防止符号链接或路径归一化越界。
     *
     * @param string $objectKey 私有证书对象键
     * @param array<int, string> $extensions 允许的扩展名
     * @return string 证书绝对路径
     */
    private function privateCertificatePath(string $objectKey, array $extensions): string
    {
        $this->assertPrivateObjectKeySyntax($objectKey, $extensions);
        $root = realpath(runtime_path(FileConstant::LOCAL_PRIVATE_DIR . '/certificate'));
        $resolved = realpath(runtime_path($objectKey));
        if (!is_string($root) || !is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
            throw new PaymentException('快钱私有证书文件不存在或不可读', 40200);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $resolved = str_replace('\\', '/', $resolved);
        $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        $comparisonResolved = DIRECTORY_SEPARATOR === '\\' ? strtolower($resolved) : $resolved;
        if (!str_starts_with($comparisonResolved, $comparisonRoot)) {
            throw new PaymentException('快钱证书 object_key 越出私有证书目录', 40200);
        }

        return $resolved;
    }

    /**
     * 校验商户号、证书和产品关联配置。
     *
     * @return void
     */
    private function validateConfiguration(): void
    {
        if (preg_match('/^\d{11}$/D', $this->configText('account_id')) !== 1) {
            throw new PaymentException('快钱商户号必须是 11 位数字', 40200);
        }
        if ($this->configText('merchant_cert_password') === '') {
            throw new PaymentException('快钱商户 PFX 证书密码不能为空', 40200);
        }
        $this->assertPrivateObjectKeySyntax($this->configText('platform_cert_path'), ['cer', 'crt', 'pem']);
        $this->assertPrivateObjectKeySyntax($this->configText('merchant_key_path'), ['pfx', 'p12']);

        $enabled = $this->enabledProducts();
        if ($enabled === [] || array_diff($enabled, self::FORM_PRODUCTS) !== []) {
            throw new PaymentException('快钱已开通产品配置为空或包含未知产品', 40200);
        }
        if (in_array(self::PRODUCT_CODE_26_1, $enabled, true)
            && ($this->configText('wechat_mp_app_id') === '' || $this->configText('wechat_mp_app_secret') === '')) {
            throw new PaymentException('快钱公众号产品必须配置 AppID 和 AppSecret', 40200);
        }
    }

    /**
     * 校验私有文件对象键格式。
     *
     * @param string $objectKey 私有证书对象键
     * @param array<int, string> $extensions 允许的扩展名
     * @return void
     */
    private function assertPrivateObjectKeySyntax(string $objectKey, array $extensions): void
    {
        $objectKey = str_replace('\\', '/', trim($objectKey));
        $segments = explode('/', $objectKey);
        if ($objectKey === ''
            || str_contains($objectKey, "\0")
            || str_contains($objectKey, '://')
            || str_starts_with($objectKey, '/')
            || preg_match('/^[A-Za-z]:\//D', $objectKey) === 1
            || preg_match('#^storage/private/certificate/[A-Za-z0-9][A-Za-z0-9_./-]*$#D', $objectKey) !== 1
            || array_filter($segments, static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..') !== []
            || !in_array(strtolower(pathinfo($objectKey, PATHINFO_EXTENSION)), $extensions, true)) {
            throw new PaymentException('快钱证书配置必须是对应类型的私有证书 object_key', 40200);
        }
    }

    /**
     * 拒绝当前产品不支持的小程序身份。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @return void
     */
    private function assertNoMiniProgramIdentity(array $payment): void
    {
        $method = strtolower($this->firstText($payment['method'] ?? ''));
        if ($this->firstText($payment['mini_openid'] ?? '') !== ''
            || filter_var($payment['is_mini'] ?? false, FILTER_VALIDATE_BOOL)
            || in_array($method, ['mini', 'mini_program', 'wechat_mini'], true)) {
            throw new UnsupportedPaymentOperationException('快钱当前插件仅支持公众号身份，不支持 mini_openid', 40200);
        }
    }

    /**
     * 校验微信应用身份作用域。
     *
     * @param array<string, mixed> $payment 支付扩展参数
     * @param string $expectedAppId 当前公众号 AppID
     * @return void
     */
    private function assertWechatAppScope(array $payment, string $expectedAppId): void
    {
        if ($expectedAppId === '') {
            throw new PaymentException('快钱微信公众号 AppID 未配置', 40200);
        }
        $provided = $this->firstText($payment['sub_appid'] ?? '', $payment['app_id'] ?? '');
        if ($provided !== '' && !hash_equals($expectedAppId, $provided)) {
            throw new PaymentException('快钱公众号身份 AppID 与当前通道配置不一致', 40200);
        }
    }

    /**
     * 校验快钱产品属于当前平台支付方式。
     *
     * @param string $product 快钱支付产品编码
     * @param string $payType 平台支付方式编码
     * @return void
     */
    private function assertProductMatchesPayType(string $product, string $payType): void
    {
        $allowed = match ($payType) {
            'alipay' => [self::PRODUCT_CODE_27_3],
            'wxpay' => [self::PRODUCT_CODE_26_1, self::PRODUCT_CODE_26_2],
            'bank' => [self::PRODUCT_CODE_21, self::PRODUCT_CODE_00, self::PRODUCT_CODE_10],
            default => [],
        };
        if (!in_array($product, $allowed, true)) {
            throw new PaymentDefinitiveException('快钱产品与支付方式不匹配', 40200);
        }
    }

    /**
     * 读取当前支付方式。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     * @return string 平台支付方式编码
     */
    private function payType(array $order): string
    {
        $payType = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        if (!in_array($payType, ['alipay', 'wxpay', 'bank'], true)) {
            throw new PaymentDefinitiveException('快钱不支持当前支付方式', 40200);
        }

        return $payType;
    }

    /**
     * 读取标准支付载体参数。
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

    private function merchantAccountId(): string
    {
        return $this->configText('account_id') . '01';
    }

    private function gatewayOrderNo(mixed $value): string
    {
        $payNo = $this->requiredText($value, '快钱订单号不能为空');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,29}$/D', $payNo) !== 1) {
            throw new PaymentDefinitiveException('快钱订单号必须是 1-30 位字母、数字、下划线或短横线', 40200);
        }

        return $payNo;
    }

    private function positiveCents(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new PaymentDefinitiveException($message, 40200);
        }
        $amount = (int) $value;
        if ($amount <= 0 || strlen((string) $amount) > 10) {
            throw new PaymentDefinitiveException($message, 40200);
        }

        return $amount;
    }

    private function nonNegativeCents(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new PaymentException($message, 40200);
        }

        return (int) $value;
    }

    private function absoluteUrl(mixed $value, string $label): string
    {
        $url = $this->requiredText($value, $label . '不能为空');
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new PaymentDefinitiveException($label . '必须是 HTTP(S) 绝对地址', 40200);
        }

        return $url;
    }

    private function optionalAbsoluteUrl(mixed $value, string $label): string
    {
        $url = $this->firstText($value);

        return $url === '' ? '' : $this->absoluteUrl($url, $label);
    }

    private function requiredGatewayTime(mixed $value, string $message): string
    {
        $text = $this->firstText($value);
        if (preg_match('/^\d{14}$/D', $text) !== 1) {
            throw new PaymentException($message, 40200);
        }
        $date = DateTimeImmutable::createFromFormat('!YmdHis', $text);
        if (!$date instanceof DateTimeImmutable || $date->format('YmdHis') !== $text) {
            throw new PaymentException($message, 40200);
        }

        return $date->format('Y-m-d H:i:s');
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = $this->firstText($value);
        if ($text === '') {
            throw new PaymentException($message, 40200);
        }

        return $text;
    }

    private function notifyMessage(string $payResult, mixed $errCode): string
    {
        $error = preg_replace('/[^A-Za-z0-9_.-]/', '', $this->firstText($errCode)) ?: '';

        return $error === '' ? '快钱 payResult=' . $payResult : '快钱 payResult=' . $payResult . ', errCode=' . $error;
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

    /**
     * 构建必填输入框配置项。
     *
     * @param string $field 配置字段名
     * @param string $title 配置项标题
     * @return array<string, mixed> 必填输入框配置
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
     * 构建私有文件上传配置。
     *
     * @param string $accept 允许上传的扩展名
     * @return array<string, mixed> 私有文件上传配置
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
}
