<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\passpay\PasspayClient;
use app\common\sdk\passpay\PasspaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 精秀支付 API 插件。
 */
class PasspayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_WECHAT_PUB = 'wechatPub';
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
                self::PRODUCT_WECHAT_PUB => '微信 JSAPI',
                self::PRODUCT_ALIPAY_PUB => '支付宝 JSAPI',
                self::PRODUCT_WECHAT_WAP => '微信 H5',
                self::PRODUCT_ALIPAY_WAP => '支付宝 H5',
                self::PRODUCT_ALIPAY_PC => '支付宝电脑网站',
                self::PRODUCT_WECHAT_QR => '微信扫码',
                self::PRODUCT_ALIPAY_QR => '支付宝扫码',
                self::PRODUCT_QQ_QR => 'QQ 扫码',
                self::PRODUCT_UNION_QR => '银联扫码',
            ]),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];

        return $this->executeDirectPaymentProduct($order, [

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_PUB,
                    'wxpay' => self::PRODUCT_WECHAT_PUB,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAP,
                    'wxpay' => self::PRODUCT_WECHAT_WAP,
                ],
                'handler' => fn (): array => match ($payType) {
                    'wxpay' => $this->tradePay($order, 'wechatWap'),
                    'alipay' => $this->tradePay($order, 'alipayWap'),
                    default => throw new PaymentException('精秀支付当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
                },
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAP,
                    'wxpay' => self::PRODUCT_WECHAT_WAP,
                ],
                'handler' => fn (): array => match ($payType) {
                    'wxpay' => $this->tradePay($order, 'wechatWap'),
                    'alipay' => $this->tradePay($order, 'alipayWap'),
                    default => throw new PaymentException('精秀支付当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
                },
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_PC,
                ],
                'handler' => fn (): array => $payType === 'alipay'
                ? $this->tradePay($order, 'alipayPc')
                : throw new PaymentException('精秀支付当前支付方式不支持网页产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
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
        ], '精秀支付');
    }

    /**
     * 按精秀 trade_type 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $tradeType 精秀支付产品
     * @return array<string, mixed>
     */
    private function tradePay(array $order, string $tradeType): array
    {
        try {
            $data = $this->client()->execute('pay.order/create', $this->basePayload($order) + ['trade_type' => $tradeType]);
        } catch (PasspaySdkException $e) {
            throw new PaymentException('精秀支付下单失败：' . $e->getMessage(), 40200);
        }

        $url = (string) ($data['payurl'] ?? '');
        if ($url === '') {
            throw new PaymentException('精秀支付未返回支付链接', 40200, ['response' => $data]);
        }

        $page = str_contains(strtolower($tradeType), 'wap') || str_contains(strtolower($tradeType), 'pc') ? 'jump' : 'qrcode';
        $params = $page === 'jump' ? ['url' => $url, 'raw' => $data] : ['qrcode' => $url, 'raw' => $data];

        return $this->payResult($page, (string) $order['pay_type_code'], $tradeType, 'pay.order/create', $params, $data, $order);
    }

    /**
     * 精秀旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '精秀支付插件暂不支持主动查单'];
    }

    /**
     * 精秀旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '精秀支付插件暂不支持关单'];
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
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['trade_no'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['refund_amount'] ?? 0)) * 100),
            'raw_data' => $data,
        ];
    }

    /**
     * 解析支付回调。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
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
            'message' => (string) ($payload['order_status'] ?? ''),
            'channel_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'channel_trade_no' => (string) ($payload['trade_no'] ?? $payload['channel_order_sn'] ?? ''),
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
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $payment = (array) ($order['extra']['payment'] ?? []);
        $tradeType = $payType === 'wxpay' ? 'wechatPub' : 'alipayPub';
        try {
            $data = $this->client()->execute('pay.order/create', $this->basePayload($order) + [
                'trade_type' => $tradeType,
                'sub_appid' => (string) ($payment['sub_appid'] ?? ''),
                'user_id' => (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? $payment['buyer_id'] ?? ''),
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

        return $this->payResult('jsapi', $payType, $tradeType, 'pay.order/create', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
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
     * 包装标准支付结果。
     *
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
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
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
