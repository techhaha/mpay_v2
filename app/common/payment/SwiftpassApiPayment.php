<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\swiftpass\SwiftpassClient;
use app\common\sdk\swiftpass\SwiftpassSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 威富通 RSA 支付 API 插件。
 *
 * 负责微信、支付宝、QQ、银联和京东支付产品的下单、退款及异步通知适配；当前协议未接入主动查单与关单能力。
 */
class SwiftpassApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_PAY_WEIXIN_JSPAY = 'pay.weixin.jspay';
    private const PRODUCT_PAY_ALIPAY_JSPAY = 'pay.alipay.jspay';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_PAY_WEIXIN_WAPPAY = 'pay.weixin.wappay';
    private const PRODUCT_PAY_WEIXIN_NATIVE = 'pay.weixin.native';
    private const PRODUCT_PAY_ALIPAY_NATIVE = 'pay.alipay.native';
    private const PRODUCT_PAY_TENPAY_NATIVE = 'pay.tenpay.native';
    private const PRODUCT_PAY_UNIONPAY_NATIVE = 'pay.unionpay.native';
    private const PRODUCT_PAY_JDPAY_NATIVE = 'pay.jdpay.native';

    private ?SwiftpassClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'swiftpass_api',
        'name' => '威富通RSA支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.swiftpass.cn/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'qqpay', 'bank', 'jdpay'],
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
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'select', 'field' => 'sign_type', 'title' => '签名方式', 'value' => 'RSA_1_256', 'options' => [['label' => 'RSA-SHA256', 'value' => 'RSA_1_256'], ['label' => 'RSA-SHA1', 'value' => 'RSA_1_1'], ['label' => 'MD5', 'value' => 'MD5']]],
            ['type' => 'password', 'field' => 'key', 'title' => 'MD5密钥', 'value' => ''],
            ['type' => 'textarea', 'field' => 'rsa_private_key', 'title' => '商户RSA私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户RSA私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'rsa_public_key', 'title' => '平台RSA公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台RSA公钥不能为空']]],
            ['type' => 'input', 'field' => 'gateway_url', 'title' => '自定义网关', 'value' => '', 'props' => ['placeholder' => '留空使用威富通默认网关']],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_PAY_WEIXIN_JSPAY => '微信 JSAPI',
                self::PRODUCT_PAY_ALIPAY_JSPAY => '支付宝 JSAPI',
                self::PRODUCT_WXPAY_MINI => '微信小程序',
                self::PRODUCT_PAY_WEIXIN_WAPPAY => '微信 H5',
                self::PRODUCT_PAY_WEIXIN_NATIVE => '微信扫码',
                self::PRODUCT_PAY_ALIPAY_NATIVE => '支付宝扫码',
                self::PRODUCT_PAY_TENPAY_NATIVE => 'QQ 扫码',
                self::PRODUCT_PAY_UNIONPAY_NATIVE => '银联扫码',
                self::PRODUCT_PAY_JDPAY_NATIVE => '京东扫码',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'mini_program', 'alipay_oauth']),
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
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '威富通');
    }

    /**
     * 预检实际产品的身份，小程序与公众号即便共用服务码也必须独立授权。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 当前产品的身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_PAY_WEIXIN_JSPAY => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_WXPAY_MINI => $this->directPaymentIdentityRequirement($order, $product, 'mini_program'),
            self::PRODUCT_PAY_ALIPAY_JSPAY => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
            default => null,
        };
    }

    /**
     * 声明当前可调用产品；银联客户端身份协议尚未核验，不声明银联 JSAPI。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, array<string, mixed>> 产品与处理器声明
     */
    private function paymentProducts(array $order): array
    {
        $payType = (string) $order['pay_type_code'];

        return [

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_PAY_ALIPAY_JSPAY,
                    'wxpay' => self::PRODUCT_PAY_WEIXIN_JSPAY,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'mini' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_MINI],
                'handler' => fn (): array => $this->jsapiPay($order, true),
            ],
            'h5' => [
                'products' => [
                    'wxpay' => self::PRODUCT_PAY_WEIXIN_WAPPAY,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                    ? $this->wxH5Pay($order)
                    : throw new PaymentProductUnavailableException('威富通当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'jump' => [
                'products' => [
                    'wxpay' => self::PRODUCT_PAY_WEIXIN_WAPPAY,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->wxH5Pay($order)
                : throw new PaymentProductUnavailableException('威富通当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_PAY_ALIPAY_NATIVE,
                    'wxpay' => self::PRODUCT_PAY_WEIXIN_NATIVE,
                    'qqpay' => self::PRODUCT_PAY_TENPAY_NATIVE,
                    'bank' => self::PRODUCT_PAY_UNIONPAY_NATIVE,
                    'jdpay' => self::PRODUCT_PAY_JDPAY_NATIVE,
                ],
                'handler' => fn (): array => $this->nativePay($order, $payType),
            ],
        ];
    }

    /**
     * Native 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function nativePay(array $order, string $payType): array
    {
        $service = match ($payType) {
            'wxpay' => 'pay.weixin.native',
            'qqpay' => 'pay.tenpay.native',
            'bank' => 'pay.unionpay.native',
            'jdpay' => 'pay.jdpay.native',
            default => 'pay.alipay.native',
        };

        try {
            $data = $this->client()->request($this->basePayload($order) + ['service' => $service]);
        } catch (SwiftpassSdkException $e) {
            throw new PaymentException('威富通下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['code_url'] ?? '');
        if (str_contains($qrcode, 'myun.tenpay.com')) {
            $parts = explode('&t=', $qrcode);
            $qrcode = isset($parts[1]) ? 'https://qpay.qq.com/qr/' . $parts[1] : $qrcode;
        }
        if ($qrcode === '') {
            throw new PaymentException('威富通未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $service, $service, ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('威富通插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('威富通插件暂不支持关单', 40200);
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
            $data = $this->client()->request([
                'service' => 'unified.trade.refund',
                'transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
                'out_refund_no' => (string) $order['refund_no'],
                'total_fee' => (string) (int) $order['amount'],
                'refund_fee' => (string) (int) $order['refund_amount'],
                'op_user_id' => $this->configText('mch_id'),
            ]);
        } catch (SwiftpassSdkException $e) {
            throw new PaymentUncertainException('威富通退款结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) ($data['refund_fee'] ?? $order['refund_amount']),
            'chan_refund_no' => (string) ($data['refund_id'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析支付回调。
     *
     * SDK 负责解析并验证原始通知内容，只有通信状态和业务状态均为 0 才确认支付成功。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        try {
            $payload = $this->client()->notify($request->rawBody());
        } catch (SwiftpassSdkException $e) {
            throw new PaymentException($e->getMessage(), 40200);
        }

        $success = (string) ($payload['status'] ?? '') === '0' && (string) ($payload['result_code'] ?? '') === '0';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'pay_no' => trim((string) ($payload['out_trade_no'] ?? '')),
            'paid_amount' => $success ? $this->integerCents($payload['total_fee'] ?? null, '威富通回调金额') : null,
            'message' => (string) ($payload['err_msg'] ?? $payload['message'] ?? ''),
            'chan_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'chan_trade_no' => (string) ($payload['transaction_id'] ?? ''),
            'channel_status' => (string) ($payload['result_code'] ?? $payload['status'] ?? ''),
        ];
    }

    /**
     * 返回威富通成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回威富通失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'failure';
    }

    /**
     * 解析渠道以整数分表示的金额，拒绝小数及非数字内容。
     *
     * @param mixed $value 渠道金额原值
     * @param string $field 用于异常提示的字段名称
     */
    private function integerCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+$/', $text) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return (int) $text;
    }

    /**
     * 根据支付方式补充对应的用户身份并发起 JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @param bool $mini 是否调用已选定的小程序产品
     * @return array<string, mixed> 标准 JSAPI 或小程序待支付结果
     */
    private function jsapiPay(array $order, bool $mini = false): array
    {
        $payType = (string) $order['pay_type_code'];
        $identity = $this->directPaymentIdentityData($order, $payType === 'alipay' ? 'alipay_oauth' : ($mini ? 'mini_program' : 'wechat_oauth'));
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('威富通必须先取得当前产品的用户身份', 40200);
        }
        $service = $payType === 'wxpay' ? self::PRODUCT_PAY_WEIXIN_JSPAY : self::PRODUCT_PAY_ALIPAY_JSPAY;
        $product = $mini ? self::PRODUCT_WXPAY_MINI : $service;

        $payload = $this->basePayload($order) + ['service' => $service];
        if ($payType === 'wxpay') {
            $payload['is_raw'] = '1';
            $payload['is_minipg'] = $mini ? '1' : '0';
            $payload['sub_appid'] = $identity['app_id'];
            $payload['sub_openid'] = $identity['identity'];
            $payload['device_info'] = 'AND_WAP';
        } else {
            $payload['buyer_id'] = $identity['identity'];
        }

        try {
            $data = $this->client()->request($payload);
        } catch (SwiftpassSdkException $e) {
            throw new PaymentException('威富通JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['pay_info'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        $params = $mini
            ? ['_page' => 'wechatMini', 'request_payment' => (array) $payInfo, 'raw' => $data]
            : ((array) $payInfo) + ['raw' => $data];

        return $this->payResult($mini ? 'page' : 'jsapi', $payType, $product, $service, $params, $data, $order);
    }

    /**
     * 微信 H5 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准跳转待支付结果
     */
    private function wxH5Pay(array $order): array
    {
        try {
            $data = $this->client()->request($this->basePayload($order) + [
                'service' => 'pay.weixin.wappay',
                'device_info' => 'AND_WAP',
                'mch_app_name' => mb_strcut((string) $order['subject'], 0, 32, 'UTF-8'),
                'mch_app_id' => (string) $order['return_url'],
                'callback_url' => (string) $order['return_url'],
            ]);
        } catch (SwiftpassSdkException $e) {
            throw new PaymentException('威富通微信H5下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('jump', 'wxpay', 'pay.weixin.wappay', 'pay.weixin.wappay', ['url' => (string) ($data['pay_info'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 构造威富通各支付产品共享的下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 上游下单请求参数
     */
    private function basePayload(array $order): array
    {
        return [
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'total_fee' => (string) (int) $order['amount'],
            'mch_create_ip' => (string) $order['client_ip'],
            'out_trade_no' => (string) $order['pay_no'],
            'notify_url' => (string) $order['callback_url'],
        ];
    }

    /**
     * 将上游支付凭据包装为标准待支付结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 威富通服务代码
     * @param string $action 支付动作标识
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
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ]);
    }

    /**
     * 获取按配置签名方式和密钥初始化的 SDK 客户端。
     */
    private function client(): SwiftpassClient
    {
        if ($this->client === null) {
            $this->client = new SwiftpassClient([
                'mch_id' => $this->configText('mch_id'),
                'key' => $this->configText('key'),
                'sign_type' => $this->configText('sign_type') ?: 'RSA_1_256',
                'rsa_private_key' => $this->configText('rsa_private_key'),
                'rsa_public_key' => $this->configText('rsa_public_key'),
                'gateway_url' => $this->configText('gateway_url'),
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
