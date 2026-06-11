<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\haipay\HaipayClient;
use app\common\sdk\haipay\HaipaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 海科融通聚合支付 API 插件。
 */
class HaipayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALI_JSAPI = 'ALI_JSAPI';
    private const PRODUCT_WX_JSAPI = 'WX_JSAPI';
    private const PRODUCT_ALI = 'ALI';
    private const PRODUCT_WX = 'WX';
    private const PRODUCT_UNIONQR = 'UNIONQR';
    private const PRODUCT_PASSIVE_PAY = 'passive_pay';

    private ?HaipayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'haipay_api',
        'name' => '海科融通支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.hkrt.cn/',
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
            ['type' => 'input', 'field' => 'access_id', 'title' => 'AccessID', 'value' => '', 'validate' => [['required' => true, 'message' => 'AccessID不能为空']]],
            ['type' => 'password', 'field' => 'access_key', 'title' => 'AccessKey', 'value' => '', 'validate' => [['required' => true, 'message' => 'AccessKey不能为空']]],
            ['type' => 'input', 'field' => 'agent_no', 'title' => '代理商编号', 'value' => '', 'validate' => [['required' => true, 'message' => '代理商编号不能为空']]],
            ['type' => 'input', 'field' => 'merchant_no', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'input', 'field' => 'pn', 'title' => '产品编号', 'value' => '', 'validate' => [['required' => true, 'message' => '产品编号不能为空']]],
            ['type' => 'switch', 'field' => 'sandbox', 'title' => '测试环境', 'value' => false],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALI_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WX_JSAPI => '微信 JSAPI/小程序',
                self::PRODUCT_ALI => '支付宝扫码',
                self::PRODUCT_WX => '微信扫码',
                self::PRODUCT_UNIONQR => '银联扫码',
                self::PRODUCT_PASSIVE_PAY => '付款码支付',
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
                    'alipay' => self::PRODUCT_PASSIVE_PAY,
                    'wxpay' => self::PRODUCT_PASSIVE_PAY,
                    'bank' => self::PRODUCT_PASSIVE_PAY,
                ],
                'handler' => fn (): array => $this->passivePay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALI_JSAPI,
                    'wxpay' => self::PRODUCT_WX_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALI,
                    'wxpay' => self::PRODUCT_WX,
                    'bank' => self::PRODUCT_UNIONQR,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ], '海科融通');
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function scanPay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $channelType = match ($payType) {
            'wxpay' => 'WX',
            'bank' => 'UNIONQR',
            default => 'ALI',
        };

        try {
            $data = $this->client()->post('/api/v2/pay/pre-pay', $this->basePayload($order) + [
                'pay_type' => $channelType,
                'pay_mode' => 'NATIVE',
            ]);
        } catch (HaipaySdkException $e) {
            throw new PaymentException('海科融通下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['ali_qr_code'] ?? $data['wc_qr_code'] ?? $data['uniqr_qr_code'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('海科融通未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $channelType, 'pre-pay', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->post('/api/v2/pay/order-query', [
                'agent_no' => $this->configText('agent_no'),
                'merch_no' => $this->configText('merchant_no'),
                'out_trade_no' => (string) $order['pay_no'],
                'pn' => $this->configText('pn'),
            ]);
        } catch (HaipaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        $success = (string) ($data['trade_status'] ?? '') === '1';
        return [
            'success' => true,
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'channel_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['trade_no'] ?? $order['chan_trade_no'] ?? ''),
            'channel_status' => (string) ($data['trade_status'] ?? ''),
            'message' => (string) ($data['trade_status'] ?? ''),
            'raw_data' => $data,
        ];
    }

    /**
     * 关闭订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        try {
            $data = $this->client()->post('/api/v2/pay/close-order', [
                'agent_no' => $this->configText('agent_no'),
                'merch_no' => $this->configText('merchant_no'),
                'out_trade_no' => (string) $order['pay_no'],
                'pn' => $this->configText('pn'),
            ]);
        } catch (HaipaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return ['success' => true, 'status' => PaymentPluginStatusConstant::CLOSED, 'raw_data' => $data];
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
            $data = $this->client()->post('/api/v2/pay/refund', [
                'agent_no' => $this->configText('agent_no'),
                'merch_no' => $this->configText('merchant_no'),
                'trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'out_refund_no' => (string) $order['refund_no'],
                'refund_amount' => FormatHelper::amount((int) $order['refund_amount']),
                'pn' => $this->configText('pn'),
            ]);
        } catch (HaipaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refund_no'] ?? $order['refund_no']),
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
        $payload = (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('海科融通回调验签失败', 40200);
        }

        $success = (string) ($payload['trade_status'] ?? '') === '1';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['trade_status'] ?? ''),
            'channel_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'channel_trade_no' => (string) ($payload['trade_no'] ?? $payload['bank_trade_no'] ?? ''),
            'channel_status' => (string) ($payload['trade_status'] ?? ''),
        ];
    }

    /**
     * 返回海科融通成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return json_encode(['return_code' => 'SUCCESS'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 返回海科融通失败应答。
     */
    public function notifyFail(): string|Response
    {
        return json_encode(['return_code' => 'FAIL'], JSON_UNESCAPED_UNICODE);
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
        $channelType = $payType === 'wxpay' ? 'WX' : 'ALI';
        $payload = $this->basePayload($order) + ['pay_type' => $channelType, 'pay_mode' => 'JSAPI'];
        if ($payType === 'wxpay') {
            $payload['openid'] = (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? '');
            $payload['appid'] = (string) ($payment['sub_appid'] ?? '');
        } else {
            $payload['buyer_id'] = (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '');
        }

        try {
            $data = $this->client()->post('/api/v2/pay/pre-pay', $payload);
        } catch (HaipaySdkException $e) {
            throw new PaymentException('海科融通JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $payType === 'wxpay'
            ? ((array) json_decode((string) ($data['wc_pay_data'] ?? ''), true))
            : ['tradeNO' => (string) ($data['ali_trade_no'] ?? '')];
        $payInfo['raw'] = $data;

        return $this->payResult('jsapi', $payType, $channelType . '_JSAPI', 'pre-pay', $payInfo, $data, $order);
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function passivePay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $authCode = (string) ($payment['auth_code'] ?? '');
        if ($authCode === '') {
            throw new PaymentException('海科融通付款码支付缺少付款码', 40200);
        }

        try {
            $data = $this->client()->post('/api/v2/pay/passive-pay', $this->basePayload($order) + [
                'auth_code' => $authCode,
                'terminal_info' => ['device_ip' => (string) $order['client_ip']],
            ]);
        } catch (HaipaySdkException $e) {
            throw new PaymentException('海科融通付款码下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['trade_status'] ?? '') === '1') {
            return $this->payResult('ok', (string) $order['pay_type_code'], self::PRODUCT_PASSIVE_PAY, 'passive-pay', [
                'message' => '支付成功',
                'raw' => $data,
            ], $data, $order);
        }

        return $this->waitPassivePay($order, $data);
    }

    /**
     * 等待付款码用户确认。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, mixed> $rawData 原始下单结果
     * @return array<string, mixed>
     */
    private function waitPassivePay(array $order, array $rawData): array
    {
        $tradeNo = (string) ($rawData['trade_no'] ?? '');
        if ($tradeNo === '') {
            throw new PaymentException('海科融通付款码下单未返回渠道订单号', 40200, ['response' => $rawData]);
        }

        for ($index = 0; $index < 6; $index++) {
            sleep(3);
            try {
                $query = $this->client()->post('/api/v2/pay/order-query', [
                    'merch_no' => $this->configText('merchant_no'),
                    'trade_no' => $tradeNo,
                ]);
            } catch (HaipaySdkException $e) {
                throw new PaymentException('海科融通付款码查单失败：' . $e->getMessage(), 40200);
            }

            if ((string) ($query['trade_status'] ?? '') === '1') {
                return $this->payResult('ok', (string) $order['pay_type_code'], self::PRODUCT_PASSIVE_PAY, 'passive-pay', [
                    'message' => '支付成功',
                    'raw' => $query,
                ], $query, $order);
            }
            if (!in_array((string) ($query['tranSts'] ?? $query['trade_status'] ?? ''), ['3', 'USERPAYING', 'NOTPAY'], true)) {
                throw new PaymentException('海科融通付款码支付失败：订单超时或用户取消支付', 40200, ['response' => $query]);
            }
        }

        $this->close($order);

        throw new PaymentException('海科融通付款码支付失败：等待用户确认超时', 40200, ['response' => $rawData]);
    }

    /**
     * 构造预下单公共参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        return [
            'agent_no' => $this->configText('agent_no'),
            'merch_no' => $this->configText('merchant_no'),
            'out_trade_no' => (string) $order['pay_no'],
            'total_amount' => FormatHelper::amount((int) $order['amount']),
            'pn' => $this->configText('pn'),
            'notify_url' => (string) $order['callback_url'],
            'extend_params' => ['body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'), 'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8')],
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
    private function client(): HaipayClient
    {
        if ($this->client === null) {
            $this->client = new HaipayClient([
                'access_id' => $this->configText('access_id'),
                'access_key' => $this->configText('access_key'),
                'sandbox' => (bool) $this->getConfig('sandbox', false),
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
