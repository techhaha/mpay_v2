<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\xsy\XsyClient;
use app\common\sdk\xsy\XsySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 新生易支付 API 插件。
 */
class XsyApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_REVERSE_SCAN = 'reverseScan';
    private const PRODUCT_ALIPAY = 'ALIPAY';
    private const PRODUCT_WECHAT = 'WECHAT';
    private const PRODUCT_UNIONPAY = 'UNIONPAY';

    private ?XsyClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'xsy_api',
        'name' => '新生易支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.hnapay.com/',
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
            ['type' => 'input', 'field' => 'org_no', 'title' => '机构代码', 'value' => '', 'validate' => [['required' => true, 'message' => '机构代码不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'input', 'field' => 'merchant_no', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'switch', 'field' => 'is_test', 'title' => '测试环境', 'value' => false],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_REVERSE_SCAN => '付款码支付',
                self::PRODUCT_ALIPAY => '支付宝支付',
                self::PRODUCT_WECHAT => '微信支付',
                self::PRODUCT_UNIONPAY => '银联支付',
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
                    'alipay' => self::PRODUCT_REVERSE_SCAN,
                    'wxpay' => self::PRODUCT_REVERSE_SCAN,
                    'bank' => self::PRODUCT_REVERSE_SCAN,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHAT,
                    'bank' => self::PRODUCT_UNIONPAY,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHAT,
                    'bank' => self::PRODUCT_UNIONPAY,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ], '新生易');
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order): array
    {
        $payType = $this->channelPayType((string) $order['pay_type_code']);
        try {
            $data = $this->client()->request('/trade/activeScan', $this->basePayload($order) + [
                'payType' => $payType,
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentException('新生易下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['payUrl'] ?? '');
        if (str_contains($qrcode, 'qrContent=')) {
            $query = parse_url($qrcode, PHP_URL_QUERY) ?: '';
            parse_str($query, $params);
            $qrcode = (string) ($params['qrContent'] ?? $qrcode);
        }
        if ($qrcode === '') {
            throw new PaymentException('新生易未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], $payType, 'activeScan', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 查询订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->request('/trade/tradeQuery', [
                'merchantNo' => $this->configText('merchant_no'),
                'orderNo' => (string) $order['pay_no'],
            ]);
        } catch (XsySdkException $e) {
            return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'message' => $e->getMessage()];
        }

        $status = match ((string) ($data['tranSts'] ?? '')) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'FAIL' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['orderNo'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
            'channel_status' => (string) ($data['tranSts'] ?? ''),
            'message' => (string) ($data['tranSts'] ?? ''),
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
            $data = $this->client()->request('/trade/cancel', [
                'merchantNo' => $this->configText('merchant_no'),
                'orderNo' => (string) $order['pay_no'],
                'payType' => $this->channelPayType((string) $order['pay_type_code']),
            ]);
        } catch (XsySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return ['success' => true, 'msg' => '关单成功', 'raw_data' => $data];
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
            $data = $this->client()->request('/trade/refund', [
                'merchantNo' => $this->configText('merchant_no'),
                'orderNo' => (string) $order['refund_no'],
                'origOrderNo' => (string) $order['pay_no'],
                'amt' => (int) $order['refund_amount'],
            ]);
        } catch (XsySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['orderNo'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['amt'] ?? $order['refund_amount']),
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
        $raw = $request->rawBody();
        if (!$this->client()->verify($raw)) {
            throw new PaymentException('新生易回调验签失败', 40200);
        }

        $payload = (array) json_decode($raw, true);
        $data = (array) ($payload['respData'] ?? []);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'message' => (string) ($data['tranSts'] ?? 'SUCCESS'),
            'channel_order_no' => (string) ($data['orderNo'] ?? ''),
            'channel_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
            'channel_status' => (string) ($data['tranSts'] ?? 'SUCCESS'),
        ];
    }

    /**
     * 返回新生易成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '{"code":"success"}';
    }

    /**
     * 返回新生易失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '{"code":"fail"}';
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $payType = $this->channelPayType((string) $order['pay_type_code']);
        $payWay = (string) $order['pay_type_code'] === 'wxpay' ? '02' : '02';
        try {
            $data = $this->client()->request('/trade/jsapiScan', $this->basePayload($order) + [
                'payType' => $payType,
                'payWay' => $payWay,
                'subAppId' => (string) ($payment['sub_appid'] ?? ''),
                'userId' => (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? $payment['buyer_id'] ?? ''),
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentException('新生易JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $params = (string) $order['pay_type_code'] === 'wxpay'
            ? [
                'appId' => (string) ($data['payAppId'] ?? ''),
                'timeStamp' => (string) ($data['payTimeStamp'] ?? ''),
                'nonceStr' => (string) ($data['paynonceStr'] ?? ''),
                'package' => (string) ($data['payPackage'] ?? ''),
                'signType' => (string) ($data['paySignType'] ?? ''),
                'paySign' => (string) ($data['paySign'] ?? ''),
            ]
            : ['tradeNO' => (string) ($data['source'] ?? '')];

        return $this->payResult('jsapi', (string) $order['pay_type_code'], $payType, 'jsapiScan', $params + ['raw' => $data], $data, $order);
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
            $data = $this->client()->request('/trade/reverseScan', $this->basePayload($order) + [
                'payType' => $this->channelPayType((string) $order['pay_type_code']),
                'authCode' => (string) ($order['extra']['payment']['auth_code'] ?? ''),
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentException('新生易付款码下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('ok', (string) $order['pay_type_code'], 'reverseScan', 'reverseScan', ['raw' => $data], $data, $order);
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
            'merchantNo' => $this->configText('merchant_no'),
            'orderNo' => (string) $order['pay_no'],
            'amt' => (int) $order['amount'],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'trmIp' => (string) $order['client_ip'],
            'customerIp' => (string) $order['client_ip'],
            'notifyUrl' => (string) $order['callback_url'],
        ];
    }

    /**
     * 统一支付方式映射。
     */
    private function channelPayType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNIONPAY',
            default => 'ALIPAY',
        };
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
            'chan_order_no' => (string) ($data['orderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): XsyClient
    {
        if ($this->client === null) {
            $this->client = new XsyClient([
                'org_no' => $this->configText('org_no'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'is_test' => $this->getConfig('is_test', false) ? '1' : '0',
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
