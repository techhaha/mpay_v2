<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\jeepay\JeepayClient;
use app\common\sdk\jeepay\JeepaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * Jeepay 聚合支付 API 插件。
 */
class JeepayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALI_JSAPI = 'ALI_JSAPI';
    private const PRODUCT_WX_JSAPI = 'WX_JSAPI';
    private const PRODUCT_ALIPAY_WAY_CODE = 'alipay_way_code';
    private const PRODUCT_WXPAY_WAY_CODE = 'wxpay_way_code';
    private const PRODUCT_BANK_WAY_CODE = 'bank_way_code';

    private ?JeepayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'jeepay_api',
        'name' => 'Jeepay聚合支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.jeequan.com/',
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
            ['type' => 'input', 'field' => 'api_url', 'title' => '接口地址', 'value' => '', 'props' => ['placeholder' => '例如：https://pay.example.com/'], 'validate' => [['required' => true, 'message' => '接口地址不能为空']]],
            ['type' => 'input', 'field' => 'mch_no', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'input', 'field' => 'app_id', 'title' => '应用ID', 'value' => '', 'validate' => [['required' => true, 'message' => '应用ID不能为空']]],
            ['type' => 'password', 'field' => 'api_key', 'title' => '接口密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '接口密钥不能为空']]],
            ['type' => 'input', 'field' => 'alipay_way_code', 'title' => '支付宝产品编码', 'value' => 'ALI_QR'],
            ['type' => 'input', 'field' => 'wxpay_way_code', 'title' => '微信产品编码', 'value' => 'WX_NATIVE'],
            ['type' => 'input', 'field' => 'bank_way_code', 'title' => '云闪付产品编码', 'value' => 'YSF_NATIVE'],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALI_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WX_JSAPI => '微信 JSAPI',
                self::PRODUCT_ALIPAY_WAY_CODE => '支付宝产品编码',
                self::PRODUCT_WXPAY_WAY_CODE => '微信产品编码',
                self::PRODUCT_BANK_WAY_CODE => '云闪付产品编码',
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
                    'alipay' => self::PRODUCT_ALI_JSAPI,
                    'wxpay' => self::PRODUCT_WX_JSAPI,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType, $this->wayCode($payType, 'jsapi')),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAY_CODE,
                    'wxpay' => self::PRODUCT_WXPAY_WAY_CODE,
                    'bank' => self::PRODUCT_BANK_WAY_CODE,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType, $this->wayCode($payType, 'h5')),
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAY_CODE,
                    'wxpay' => self::PRODUCT_WXPAY_WAY_CODE,
                    'bank' => self::PRODUCT_BANK_WAY_CODE,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType, $this->wayCode($payType, 'h5')),
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAY_CODE,
                    'wxpay' => self::PRODUCT_WXPAY_WAY_CODE,
                    'bank' => self::PRODUCT_BANK_WAY_CODE,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType, $this->wayCode($payType, 'web')),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_WAY_CODE,
                    'wxpay' => self::PRODUCT_WXPAY_WAY_CODE,
                    'bank' => self::PRODUCT_BANK_WAY_CODE,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType, $this->wayCode($payType, '')),
            ],
        ], 'Jeepay');
    }

    /**
     * Jeepay 统一下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @param string $wayCode Jeepay 产品编码
     * @return array<string, mixed>
     */
    private function productPay(array $order, string $payType, string $wayCode): array
    {
        $payload = [
            'mchNo' => $this->configText('mch_no'),
            'appId' => $this->configText('app_id'),
            'mchOrderNo' => (string) $order['pay_no'],
            'wayCode' => $wayCode,
            'amount' => (int) $order['amount'],
            'currency' => 'cny',
            'clientIp' => (string) $order['client_ip'],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notifyUrl' => (string) $order['callback_url'],
            'returnUrl' => (string) $order['return_url'],
            'reqTime' => (string) round(microtime(true) * 1000),
            'version' => '1.0',
            'signType' => 'MD5',
        ];

        $payment = (array) ($order['extra']['payment'] ?? []);
        if ($payment !== []) {
            $payload['channelExtra'] = json_encode($payment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        try {
            $data = $this->client()->post('/api/pay/unifiedOrder', $payload);
        } catch (JeepaySdkException $e) {
            throw new PaymentException('Jeepay下单失败：' . $e->getMessage(), 40200);
        }

        return $this->buildPayResult($payType, $wayCode, $data, $order);
    }

    /**
     * Jeepay 旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => 'Jeepay插件暂不支持主动查单'];
    }

    /**
     * Jeepay 旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => 'Jeepay插件暂不支持关单'];
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
            $data = $this->client()->post('/api/refund/refundOrder', [
                'mchNo' => $this->configText('mch_no'),
                'appId' => $this->configText('app_id'),
                'mchRefundNo' => (string) $order['refund_no'],
                'payOrderId' => (string) ($order['chan_trade_no'] ?? ''),
                'refundAmount' => (int) $order['refund_amount'],
                'currency' => 'cny',
                'notifyUrl' => (string) ($order['refund_callback_url'] ?? ''),
                'reqTime' => (string) round(microtime(true) * 1000),
                'version' => '1.0',
                'signType' => 'MD5',
            ]);
        } catch (JeepaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refundOrderId'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['refundAmount'] ?? $order['refund_amount']),
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
        $payload = $request->post() ?: (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('Jeepay回调验签失败', 40200);
        }

        $success = (string) ($payload['state'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['state'] ?? ''),
            'channel_order_no' => (string) ($payload['mchOrderNo'] ?? ''),
            'channel_trade_no' => (string) ($payload['payOrderId'] ?? $payload['channelOrderNo'] ?? ''),
            'channel_status' => (string) ($payload['state'] ?? ''),
        ];
    }

    /**
     * 返回 Jeepay 成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回 Jeepay 失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 根据支付方式和场景选择 Jeepay 产品编码。
     */
    private function wayCode(string $payType, string $method): string
    {
        if ($payType === 'wxpay' && $method === 'jsapi') {
            return 'WX_JSAPI';
        }
        if ($payType === 'alipay' && $method === 'jsapi') {
            return 'ALI_JSAPI';
        }
        if ($payType === 'bank') {
            return $this->configText('bank_way_code') ?: 'YSF_NATIVE';
        }
        if ($payType === 'wxpay') {
            return $this->configText('wxpay_way_code') ?: 'WX_NATIVE';
        }

        return $this->configText('alipay_way_code') ?: 'ALI_QR';
    }

    /**
     * 按 Jeepay 返回类型映射承接页。
     *
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function buildPayResult(string $payType, string $wayCode, array $data, array $order): array
    {
        $payDataType = strtolower((string) ($data['payDataType'] ?? ''));
        $payData = $data['payData'] ?? '';
        $page = 'qrcode';
        $params = ['qrcode' => is_string($payData) ? $payData : json_encode($payData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];

        if (in_array($payDataType, ['payurl', 'url'], true)) {
            $page = 'jump';
            $params = ['url' => (string) $payData];
        } elseif (in_array($payDataType, ['form', 'html'], true)) {
            $page = 'html';
            $params = ['html' => (string) $payData];
        } elseif (str_contains($wayCode, 'JSAPI')) {
            $page = 'jsapi';
            $params = is_array($payData) ? $payData : ((array) json_decode((string) $payData, true));
        }
        $params['raw'] = $data;

        return [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $wayCode,
            'pay_action' => 'unifiedOrder',
            'pay_params' => $params,
            'chan_order_no' => (string) ($data['mchOrderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['payOrderId'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): JeepayClient
    {
        if ($this->client === null) {
            $this->client = new JeepayClient([
                'api_url' => $this->configText('api_url'),
                'api_key' => $this->configText('api_key'),
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
