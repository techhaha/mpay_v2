<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\passpay\PasspayClient;
use app\common\sdk\passpay\PasspaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 精秀支付 API 插件。
 *
 * 负责微信、支付宝、QQ 与银联直连产品的下单、退款和异步通知适配；当前协议未接入主动查单与关单能力。
 */
class PasspayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_WECHAT_PUB = 'wechatPub';
    private const PRODUCT_WECHAT_PUB_HOSTED = 'wechat_pub_hosted';
    private const PRODUCT_WECHAT_LITE = 'wechatLite';
    private const PRODUCT_ALIPAY_PUB = 'alipayPub';
    private const PRODUCT_WECHAT_WAP = 'wechatWap';
    private const PRODUCT_ALIPAY_WAP = 'alipayWap';
    private const PRODUCT_ALIPAY_PC = 'alipayPc';
    private const PRODUCT_WECHAT_QR = 'wechatQr';
    private const PRODUCT_ALIPAY_QR = 'alipayQr';
    private const PRODUCT_QQ_QR = 'qqQr';
    private const PRODUCT_UNION_QR = 'unionQr';

    private ?PasspayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'passpay_api',
        'name' => '精秀支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.jxpays.com/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'qqpay', 'bank'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    /**
     * 获取后台配置表单。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConfigSchema(): array
    {
        return [
            ['type' => 'input', 'field' => 'api_url', 'title' => 'API接口地址', 'value' => '', 'validate' => [['required' => true, 'message' => 'API接口地址不能为空']]],
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'input', 'field' => 'pay_channel_id', 'title' => '通道ID', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_WECHAT_PUB => '微信公众号（自有授权）',
                self::PRODUCT_WECHAT_PUB_HOSTED => '微信公众号（机构托管）',
                self::PRODUCT_WECHAT_LITE => '微信小程序（自有授权）',
                self::PRODUCT_ALIPAY_PUB => '支付宝生活号（机构托管）',
                self::PRODUCT_WECHAT_WAP => '微信 H5',
                self::PRODUCT_ALIPAY_WAP => '支付宝 H5',
                self::PRODUCT_ALIPAY_PC => '支付宝电脑网站',
                self::PRODUCT_WECHAT_QR => '微信扫码',
                self::PRODUCT_ALIPAY_QR => '支付宝扫码',
                self::PRODUCT_QQ_QR => 'QQ 扫码',
                self::PRODUCT_UNION_QR => '银联扫码',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'mini_program']),
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
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '精秀支付');
    }

    /**
     * 只有自有公众号/小程序需要 MPAY 身份中转；机构托管产品直接返回支付地址。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 当前产品的身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_WECHAT_PUB => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_WECHAT_LITE => $this->directPaymentIdentityRequirement($order, $product, 'mini_program'),
            default => null,
        };
    }

    /**
     * 自有身份产品与机构托管产品分开声明，托管跳转只做本地已开通产品选择。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, array<string, mixed>> 产品与处理器声明
     */
    private function paymentProducts(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $enabled = $this->directPaymentEnabledProducts();
        $jumpProducts = [
            'alipay' => in_array(self::PRODUCT_ALIPAY_WAP, $enabled, true) ? self::PRODUCT_ALIPAY_WAP : self::PRODUCT_ALIPAY_PUB,
            'wxpay' => in_array(self::PRODUCT_WECHAT_WAP, $enabled, true) ? self::PRODUCT_WECHAT_WAP : self::PRODUCT_WECHAT_PUB_HOSTED,
        ];

        return [

            'jsapi' => [
                'products' => [
                    'wxpay' => self::PRODUCT_WECHAT_PUB,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'mini' => [
                'products' => ['wxpay' => self::PRODUCT_WECHAT_LITE],
                'handler' => fn (): array => $this->jsapiPay($order, true),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAP,
                    'wxpay' => self::PRODUCT_WECHAT_WAP,
                ],
                'handler' => fn (): array => match ($payType) {
                    'wxpay' => $this->tradePay($order, 'wechatWap'),
                    'alipay' => $this->tradePay($order, 'alipayWap'),
                    default => throw new PaymentProductUnavailableException('精秀支付当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
                },
            ],

            'jump' => [
                'products' => $jumpProducts,
                'handler' => fn (): array => $this->tradePay($order, $jumpProducts[$payType]),
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_PC,
                ],
                'handler' => fn (): array => $payType === 'alipay'
                ? $this->tradePay($order, 'alipayPc')
                : throw new PaymentProductUnavailableException('精秀支付当前支付方式不支持网页产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_QR,
                    'wxpay' => self::PRODUCT_WECHAT_QR,
                    'qqpay' => self::PRODUCT_QQ_QR,
                    'bank' => self::PRODUCT_UNION_QR,
                ],
                'handler' => fn (): array => $this->tradePay($order, match ($payType) {
                    'wxpay' => 'wechatQr',
                    'qqpay' => 'qqQr',
                    'bank' => 'unionQr',
                    default => 'alipayQr',
                }),
            ],
        ];
    }

    /**
     * 按精秀 trade_type 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $tradeType 精秀支付产品
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function tradePay(array $order, string $tradeType): array
    {
        try {
            // 机构托管公众号和自有公众号使用同一上游产品码，但身份处理方式不同。
            $data = $this->client()->execute('pay.order/create', $this->basePayload($order) + [
                'trade_type' => $tradeType === self::PRODUCT_WECHAT_PUB_HOSTED ? self::PRODUCT_WECHAT_PUB : $tradeType,
            ]);
        } catch (PasspaySdkException $e) {
            throw new PaymentException('精秀支付下单失败：' . $e->getMessage(), 40200);
        }

        $url = (string) ($data['payurl'] ?? '');
        if ($url === '') {
            throw new PaymentException('精秀支付未返回支付链接', 40200, ['response' => $data]);
        }

        $page = in_array($tradeType, [self::PRODUCT_WECHAT_WAP, self::PRODUCT_ALIPAY_WAP, self::PRODUCT_ALIPAY_PC,
            self::PRODUCT_ALIPAY_PUB, self::PRODUCT_WECHAT_PUB_HOSTED], true) ? 'jump' : 'qrcode';
        $params = $page === 'jump' ? ['url' => $url, 'raw' => $data] : ['qrcode' => $url, 'raw' => $data];

        return $this->payResult($page, (string) $order['pay_type_code'], $tradeType, 'pay.order/create', $params, $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('精秀支付插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('精秀支付插件暂不支持关单', 40200);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        try {
            $data = $this->client()->execute('pay.order/refund', [
                'refund_amount' => FormatHelper::amount((int) $order['refund_amount']),
                'refund_reason' => '订单退款',
                'out_refund_no' => (string) $order['refund_no'],
                'trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            ]);
        } catch (PasspaySdkException $e) {
            throw new PaymentUncertainException('精秀支付退款结果不确定：' . $e->getMessage(), 40200);
        }

        $refundAmount = isset($data['refund_amount'])
            ? $this->yuanToCents($data['refund_amount'], '精秀支付退款金额')
            : (int) $order['refund_amount'];

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => $refundAmount,
            'chan_refund_no' => (string) ($data['trade_no'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析支付回调。
     *
     * 回调参数须通过平台公钥验签，只有 SUCCESS 状态才返回实付金额。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $request->post();
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('精秀支付回调验签失败', 40200);
        }

        $success = (string) ($payload['order_status'] ?? '') === 'SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($payload['out_trade_no'] ?? '')),
            'paid_amount' => $success ? $this->yuanToCents($payload['total_amount'] ?? null, '精秀支付回调金额') : null,
            'message' => (string) ($payload['order_status'] ?? ''),
            'chan_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'chan_trade_no' => (string) ($payload['trade_no'] ?? $payload['channel_order_sn'] ?? ''),
            'channel_status' => (string) ($payload['order_status'] ?? ''),
        ];
    }

    /**
     * 返回精秀成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回精秀失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'sign fail';
    }

    /**
     * 将上游元金额严格换算为分，拒绝负数及超过两位的小数。
     *
     * @param mixed $value 上游金额原值
     * @param string $field 用于异常提示的字段名称
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
     * 根据支付方式补充用户标识并发起 JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否调用已选定的小程序产品
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function jsapiPay(array $order, bool $mini = false): array
    {
        $identity = $this->directPaymentIdentityData($order, $mini ? 'mini_program' : 'wechat_oauth');
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('精秀自有授权产品必须先取得当前应用用户身份', 40200);
        }
        $tradeType = $mini ? self::PRODUCT_WECHAT_LITE : self::PRODUCT_WECHAT_PUB;
        try {
            $data = $this->client()->execute('pay.order/create', $this->basePayload($order) + [
                'trade_type' => $tradeType,
                'sub_appid' => $identity['app_id'],
                'user_id' => $identity['identity'],
                'channe_expend' => json_encode(['is_raw' => 1]),
            ]);
        } catch (PasspaySdkException $e) {
            throw new PaymentException('精秀支付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        $params = $mini
            ? ['_page' => 'wechatMini', 'request_payment' => (array) $payInfo, 'raw' => $data]
            : ((array) $payInfo) + ['raw' => $data];

        return $this->payResult($mini ? 'page' : 'jsapi', 'wxpay', $tradeType, 'pay.order/create', $params, $data, $order);
    }

    /**
     * 构造精秀各支付产品共享的下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 上游下单请求参数
     */
    private function basePayload(array $order): array
    {
        return [
            'pay_channel_id' => $this->configText('pay_channel_id'),
            'out_trade_no' => (string) $order['pay_no'],
            'total_amount' => FormatHelper::amount((int) $order['amount']),
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) $order['return_url'],
            'client_ip' => (string) $order['client_ip'],
        ];
    }

    /**
     * 将上游支付凭据包装为标准待支付结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 已选精秀支付产品
     * @param string $action 实际调用的上游接口
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ]);
    }

    /**
     * 获取复用商户密钥初始化的 SDK 客户端。
     */
    private function client(): PasspayClient
    {
        if ($this->client === null) {
            $this->client = new PasspayClient([
                'api_url' => $this->configText('api_url'),
                'mch_id' => $this->configText('mch_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
            ]);
        }

        return $this->client;
    }

    /**
     * 读取字符串配置，缺失时返回空字符串。
     *
     * @param string $key 配置键
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
