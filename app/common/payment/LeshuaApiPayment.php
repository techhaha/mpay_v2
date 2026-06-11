<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\leshua\LeshuaClient;
use app\common\sdk\leshua\LeshuaSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 乐刷聚合支付 API 插件。
 */
class LeshuaApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_UPLOAD_AUTHCODE = 'upload_authcode';
    private const PRODUCT_ZFBZF_JSAPI = 'ZFBZF_JSAPI';
    private const PRODUCT_WXZF_JSAPI = 'WXZF_JSAPI';
    private const PRODUCT_ZFBZF = 'ZFBZF';
    private const PRODUCT_WXZF = 'WXZF';
    private const PRODUCT_UPSMZF = 'UPSMZF';

    private ?LeshuaClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'leshua_api',
        'name' => '乐刷聚合支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'http://www.leshuazf.com/',
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
            ['type' => 'input', 'field' => 'merchant_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'password', 'field' => 'trade_key', 'title' => '交易密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '交易密钥不能为空']]],
            ['type' => 'password', 'field' => 'notify_key', 'title' => '异步通知密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '异步通知密钥不能为空']]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_UPLOAD_AUTHCODE => '付款码支付',
                self::PRODUCT_ZFBZF_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WXZF_JSAPI => '微信 JSAPI',
                self::PRODUCT_ZFBZF => '支付宝扫码',
                self::PRODUCT_WXZF => '微信扫码',
                self::PRODUCT_UPSMZF => '银联扫码',
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
        return $this->executeDirectPaymentProduct($order, [

            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_UPLOAD_AUTHCODE,
                    'wxpay' => self::PRODUCT_UPLOAD_AUTHCODE,
                    'bank' => self::PRODUCT_UPLOAD_AUTHCODE,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ZFBZF_JSAPI,
                    'wxpay' => self::PRODUCT_WXZF_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ZFBZF,
                    'wxpay' => self::PRODUCT_WXZF,
                    'bank' => self::PRODUCT_UPSMZF,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ], '乐刷');
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $payWay = match ($payType) {
            'wxpay' => 'WXZF',
            'bank' => 'UPSMZF',
            default => 'ZFBZF',
        };
        $jspayFlag = $payType === 'wxpay' ? '2' : '0';

        try {
            $data = $this->client()->request($this->basePayload($order) + [
                'service' => 'get_tdcode',
                'jspay_flag' => $jspayFlag,
                'pay_way' => $payWay,
            ]);
        } catch (LeshuaSdkException $e) {
            throw new PaymentException('乐刷下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['td_code'] ?? $data['jspay_url'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('乐刷未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $payWay, 'get_tdcode', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 乐刷旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '乐刷插件暂不支持主动查单'];
    }

    /**
     * 乐刷旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '乐刷插件暂不支持关单'];
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
                'service' => 'unified_refund',
                'leshua_order_id' => (string) ($order['chan_trade_no'] ?? ''),
                'merchant_refund_id' => (string) $order['refund_no'],
                'refund_amount' => (string) (int) $order['refund_amount'],
            ]);
        } catch (LeshuaSdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['leshua_refund_id'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['refund_amount'] ?? $order['refund_amount']),
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
        $payload = $this->client()->fromXml($request->rawBody());
        if (!$this->client()->verifyNotify($payload)) {
            throw new PaymentException('乐刷回调验签失败', 40200);
        }

        $success = (string) ($payload['status'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['status'] ?? ''),
            'channel_order_no' => (string) ($payload['third_order_id'] ?? ''),
            'channel_trade_no' => (string) ($payload['leshua_order_id'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回乐刷成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '000000';
    }

    /**
     * 返回乐刷失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
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
        $payWay = $payType === 'wxpay' ? 'WXZF' : 'ZFBZF';
        $payload = $this->basePayload($order) + [
            'service' => 'get_tdcode',
            'jspay_flag' => $payType === 'wxpay' ? '1' : '1',
            'pay_way' => $payWay,
            'sub_openid' => (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? $payment['buyer_id'] ?? ''),
            'appid' => (string) ($payment['sub_appid'] ?? ''),
        ];

        try {
            $data = $this->client()->request($payload);
        } catch (LeshuaSdkException $e) {
            throw new PaymentException('乐刷JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['jspay_info'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', $payType, $payWay . '_JSAPI', 'get_tdcode', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function scanPay(array $order): array
    {
        try {
            $data = $this->client()->request($this->basePayload($order) + [
                'service' => 'upload_authcode',
                'auth_code' => (string) ($order['extra']['payment']['auth_code'] ?? ''),
            ]);
        } catch (LeshuaSdkException $e) {
            throw new PaymentException('乐刷付款码下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('ok', (string) $order['pay_type_code'], 'upload_authcode', 'upload_authcode', ['raw' => $data], $data, $order);
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
            'third_order_id' => (string) $order['pay_no'],
            'amount' => (string) (int) $order['amount'],
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
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
            'chan_order_no' => (string) ($data['third_order_id'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['leshua_order_id'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): LeshuaClient
    {
        if ($this->client === null) {
            $this->client = new LeshuaClient([
                'merchant_id' => $this->configText('merchant_id'),
                'trade_key' => $this->configText('trade_key'),
                'notify_key' => $this->configText('notify_key'),
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
