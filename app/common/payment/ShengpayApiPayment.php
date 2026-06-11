<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\shengpay\ShengpayClient;
use app\common\sdk\shengpay\ShengpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 盛付通支付 API 插件。
 */
class ShengpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_WX_JSAPI = 'wx_jsapi';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WX_WAP = 'wx_wap';
    private const PRODUCT_ALIPAY_WAP = 'alipay_wap';
    private const PRODUCT_ALIPAY_PC = 'alipay_pc';
    private const PRODUCT_WX_NATIVE = 'wx_native';
    private const PRODUCT_ALIPAY_QR = 'alipay_qr';
    private const PRODUCT_UPACP_QR = 'upacp_qr';

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
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '盛付通公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '盛付通公钥不能为空']]],
            ['type' => 'select', 'field' => 'interface_type', 'title' => '收单接口类型', 'value' => 'online', 'options' => [['label' => '线上', 'value' => 'online'], ['label' => '线下', 'value' => 'offline']]],
            ['type' => 'input', 'field' => 'sub_mch_id', 'title' => '子商户号', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_WX_JSAPI => '微信 JSAPI',
                self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WX_WAP => '微信 H5',
                self::PRODUCT_ALIPAY_WAP => '支付宝 H5',
                self::PRODUCT_ALIPAY_PC => '支付宝电脑网站',
                self::PRODUCT_WX_NATIVE => '微信扫码',
                self::PRODUCT_ALIPAY_QR => '支付宝扫码',
                self::PRODUCT_UPACP_QR => '银联扫码',
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
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WX_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAP,
                    'wxpay' => self::PRODUCT_WX_WAP,
                ],
                'handler' => fn (): array => match ($payType) {
                    'wxpay' => $this->tradePay($order, 'wx_wap'),
                    'alipay' => $this->tradePay($order, 'alipay_wap'),
                    default => throw new PaymentException('盛付通当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
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
                    default => throw new PaymentException('盛付通当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
                },
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_PC,
                ],
                'handler' => fn (): array => $payType === 'alipay'
                ? $this->tradePay($order, 'alipay_pc')
                : throw new PaymentException('盛付通当前支付方式不支持网页产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
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
        ], '盛付通');
    }

    /**
     * 按盛付通 tradeType 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $tradeType 盛付通支付产品
     * @return array<string, mixed>
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

        return $this->payResult($page, $payType, $tradeType, 'unifiedorder', $params, $data, $order);
    }

    /**
     * 盛付通旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '盛付通插件暂不支持主动查单'];
    }

    /**
     * 盛付通旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '盛付通插件暂不支持关单'];
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
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refundId'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['refundFee'] ?? $order['refund_amount']),
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
        $payload = (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('盛付通回调验签失败', 40200);
        }

        $success = (string) ($payload['status'] ?? '') === 'PAY_SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['status'] ?? ''),
            'channel_order_no' => (string) ($payload['outTradeNo'] ?? ''),
            'channel_trade_no' => (string) ($payload['transactionId'] ?? ''),
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
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $payment = (array) ($order['extra']['payment'] ?? []);
        $tradeType = $payType === 'wxpay' ? 'wx_jsapi' : 'alipay_jsapi';
        $extra = $payType === 'wxpay'
            ? ['openId' => (string) ($payment['sub_openid'] ?? ''), 'appId' => (string) ($payment['sub_appid'] ?? '')]
            : ['openId' => (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '')];

        try {
            $data = $this->client()->execute($this->orderPath(), $this->basePayload($order) + [
                'tradeType' => $tradeType,
                'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (ShengpaySdkException $e) {
            throw new PaymentException('盛付通JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', $payType, $tradeType, 'unifiedorder', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        $payload = [
            'outTradeNo' => (string) $order['pay_no'],
            'totalFee' => (int) $order['amount'],
            'currency' => 'CNY',
            'notifyUrl' => (string) $order['callback_url'],
            'pageUrl' => (string) $order['return_url'],
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'clientIp' => (string) $order['client_ip'],
        ];
        if ($this->configText('sub_mch_id') !== '') {
            $payload['subMchId'] = $this->configText('sub_mch_id');
        }

        return $payload;
    }

    /**
     * 下单接口路径。
     */
    private function orderPath(): string
    {
        return $this->configText('interface_type') === 'offline' ? '/pay/unifiedorderOffline' : '/pay/unifiedorder';
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
            'chan_order_no' => (string) ($data['outTradeNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['transactionId'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
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
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
