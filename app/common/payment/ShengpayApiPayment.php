<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\shengpay\ShengpayClient;
use app\common\sdk\shengpay\ShengpaySdkException;
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
 * 盛付通支付 API 插件。
 *
 * 负责微信、支付宝与银联直连产品的下单、退款和异步通知适配；当前协议未接入主动查单与关单能力。
 */
class ShengpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_WX_JSAPI = 'wx_jsapi';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WX_WAP = 'wx_wap';
    private const PRODUCT_ALIPAY_WAP = 'alipay_wap';
    private const PRODUCT_ALIPAY_PC = 'alipay_pc';
    private const PRODUCT_WX_NATIVE = 'wx_native';
    private const PRODUCT_ALIPAY_QR = 'alipay_qr';
    private const PRODUCT_UPACP_QR = 'upacp_qr';
    private const PRODUCT_WX_LITE = 'wx_lite';

    private ?ShengpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'shengpay_api',
        'name' => '盛付通支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.shengpay.com/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
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
            ['type' => 'input', 'field' => 'sdp_app_id', 'title' => '盛付通应用 ID', 'value' => '', 'validate' => [['required' => true, 'message' => '盛付通应用 ID 不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '盛付通公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '盛付通公钥不能为空']]],
            ['type' => 'select', 'field' => 'interface_type', 'title' => '收单接口类型', 'value' => 'online', 'options' => [['label' => '线上', 'value' => 'online'], ['label' => '线下', 'value' => 'offline']]],
            ['type' => 'input', 'field' => 'sub_mch_id', 'title' => '子商户号', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_WX_JSAPI => '微信 JSAPI',
                self::PRODUCT_WX_LITE => '微信小程序',
                self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WX_WAP => '微信 H5',
                self::PRODUCT_ALIPAY_WAP => '支付宝 H5',
                self::PRODUCT_ALIPAY_PC => '支付宝电脑网站',
                self::PRODUCT_WX_NATIVE => '微信扫码',
                self::PRODUCT_ALIPAY_QR => '支付宝扫码',
                self::PRODUCT_UPACP_QR => '银联扫码',
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
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '盛付通');
    }

    /**
     * 按与下单一致的产品声明预检身份；公众号、小程序和支付宝 UID 不互相替代。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 当前产品的身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_WX_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_WX_LITE => $this->directPaymentIdentityRequirement($order, $product, 'mini_program'),
            self::PRODUCT_ALIPAY_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
            default => null,
        };
    }

    /**
     * 声明已实现的产品；选择器在任何请求前过滤未开通产品及不合理环境。
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
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WX_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'mini' => [
                'products' => ['wxpay' => self::PRODUCT_WX_LITE],
                'handler' => fn (): array => $this->jsapiPay($order, true),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAP,
                    'wxpay' => self::PRODUCT_WX_WAP,
                ],
                'handler' => fn (): array => match ($payType) {
                    'wxpay' => $this->tradePay($order, 'wx_wap'),
                    'alipay' => $this->tradePay($order, 'alipay_wap'),
                    default => throw new PaymentProductUnavailableException('盛付通当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
                },
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAP,
                    'wxpay' => self::PRODUCT_WX_WAP,
                ],
                'handler' => fn (): array => match ($payType) {
                    'wxpay' => $this->tradePay($order, 'wx_wap'),
                    'alipay' => $this->tradePay($order, 'alipay_wap'),
                    default => throw new PaymentProductUnavailableException('盛付通当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
                },
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_PC,
                ],
                'handler' => fn (): array => $payType === 'alipay'
                ? $this->tradePay($order, 'alipay_pc')
                : throw new PaymentProductUnavailableException('盛付通当前支付方式不支持网页产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_QR,
                    'wxpay' => self::PRODUCT_WX_NATIVE,
                    'bank' => self::PRODUCT_UPACP_QR,
                ],
                'handler' => fn (): array => $this->tradePay($order, match ($payType) {
                    'wxpay' => 'wx_native',
                    'bank' => 'upacp_qr',
                    default => 'alipay_qr',
                }),
            ],
        ];
    }

    /**
     * 按盛付通 tradeType 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $tradeType 盛付通支付产品
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function tradePay(array $order, string $tradeType): array
    {
        try {
            $data = $this->client()->execute($this->orderPath(), $this->basePayload($order) + [
                'tradeType' => $tradeType,
            ]);
        } catch (ShengpaySdkException $e) {
            throw new PaymentException('盛付通下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = (string) ($data['payInfo'] ?? '');
        if ($payInfo === '') {
            throw new PaymentException('盛付通未返回支付参数', 40200, ['response' => $data]);
        }

        $page = str_contains($tradeType, '_wap') || str_contains($tradeType, '_pc') ? 'jump' : 'qrcode';
        $params = $page === 'jump' ? ['url' => $payInfo, 'raw' => $data] : ['qrcode' => $payInfo, 'raw' => $data];

        return $this->payResult($page, (string) $order['pay_type_code'], $tradeType, 'unifiedorder', $params, $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('盛付通插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('盛付通插件暂不支持关单', 40200);
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
            $data = $this->client()->execute('/refund/orderRefund', [
                'outTradeNo' => (string) $order['pay_no'],
                'outRefundNo' => (string) $order['refund_no'],
                'refundFee' => (int) $order['refund_amount'],
            ]);
        } catch (ShengpaySdkException $e) {
            throw new PaymentUncertainException('盛付通退款结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) ($data['refundFee'] ?? $order['refund_amount']),
            'chan_refund_no' => (string) ($data['refundId'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析支付回调。
     *
     * 回调使用原始 JSON 请求体解析，参数须通过盛付通平台公钥验签；只有 PAY_SUCCESS 才确认实付金额。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('盛付通回调验签失败', 40200);
        }

        $success = (string) ($payload['status'] ?? '') === 'PAY_SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($payload['outTradeNo'] ?? '')),
            'paid_amount' => $success ? $this->integerCents($payload['totalFee'] ?? null, '盛付通回调金额') : null,
            'message' => (string) ($payload['status'] ?? ''),
            'chan_order_no' => (string) ($payload['outTradeNo'] ?? ''),
            'chan_trade_no' => (string) ($payload['transactionId'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回盛付通成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回盛付通失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'FAIL';
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
     * 根据支付方式补充用户与应用标识并发起 JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否调用已选定的小程序产品
     *
     * @return array<string, mixed> 标准 JSAPI 待支付结果
     */
    private function jsapiPay(array $order, bool $mini = false): array
    {
        $payType = (string) $order['pay_type_code'];
        $identity = $this->directPaymentIdentityData($order, $payType === 'alipay' ? 'alipay_oauth' : ($mini ? 'mini_program' : 'wechat_oauth'));
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('盛付通必须先取得当前产品的用户身份', 40200);
        }
        $tradeType = $payType === 'alipay' ? self::PRODUCT_ALIPAY_JSAPI : ($mini ? self::PRODUCT_WX_LITE : self::PRODUCT_WX_JSAPI);
        $extra = $payType === 'wxpay'
            ? ['openId' => $identity['identity'], 'appId' => $identity['app_id']]
            : ['openId' => $identity['identity']];

        $payload = $this->basePayload($order);
        $payload['tradeType'] = $tradeType;
        $payload['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $data = $this->client()->execute($this->orderPath(), $payload);
        } catch (ShengpaySdkException $e) {
            throw new PaymentException('盛付通JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        $params = $mini
            ? ['_page' => 'wechatMini', 'request_payment' => (array) $payInfo, 'raw' => $data]
            : ((array) $payInfo) + ['raw' => $data];

        return $this->payResult($mini ? 'page' : 'jsapi', $payType, $tradeType, 'unifiedorder', $params, $data, $order);
    }

    /**
     * 构造盛付通各支付产品共享的下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 上游下单请求参数
     */
    private function basePayload(array $order): array
    {
        $payload = [
            'sdpAppId' => $this->configText('sdp_app_id'),
            'outTradeNo' => (string) $order['pay_no'],
            'timeExpire' => date('YmdHis', time() + 900),
            'totalFee' => (int) $order['amount'],
            'currency' => 'CNY',
            'notifyUrl' => (string) $order['callback_url'],
            'pageUrl' => (string) $order['return_url'],
            'body' => mb_strcut((string) $order['subject'], 0, 64, 'UTF-8'),
            'extra' => '{}',
            'clientIp' => (string) $order['client_ip'],
        ];
        if ($this->configText('sub_mch_id') !== '') {
            $payload['subMchId'] = $this->configText('sub_mch_id');
        }

        return $payload;
    }

    /**
     * 根据收单接口类型选择线上或线下下单路径。
     */
    private function orderPath(): string
    {
        return $this->configText('interface_type') === 'offline' ? '/pay/unifiedorderOffline' : '/pay/unifiedorder';
    }

    /**
     * 将上游支付凭据包装为标准待支付结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 已选盛付通产品代码
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
            'chan_order_no' => (string) ($data['outTradeNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['transactionId'] ?? ''),
        ]);
    }

    /**
     * 获取复用商户私钥与平台公钥初始化的 SDK 客户端。
     */
    private function client(): ShengpayClient
    {
        if ($this->client === null) {
            $this->client = new ShengpayClient([
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
