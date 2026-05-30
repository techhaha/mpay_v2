<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\tianquetech\TianqueTechClient;
use app\common\sdk\tianquetech\TianqueTechSdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 天阙科技 OpenAPI 支付插件。
 *
 * 迁移自彩虹易支付 `tianquetech` 插件。插件消费 MPAY 标准订单入参，
 * 统一返回支付承接结构，回调只做验签和结果归一。
 */
class TianqueTechApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private ?TianqueTechClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'tianquetech_api',
        'name' => '天阙科技OpenAPI支付',
        'author' => 'MPAY',
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
            [
                'type' => 'input',
                'field' => 'org_id',
                'title' => '机构编号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '机构编号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户编号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户编号不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '平台公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [
                    ['required' => true, 'message' => '平台公钥不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户私钥',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [
                    ['required' => true, 'message' => '商户私钥不能为空'],
                ],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通产品',
                'value' => [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_WXPAY_SCAN, self::PRODUCT_BANK_SCAN],
                'options' => [
                    ['label' => '支付宝扫码', 'value' => self::PRODUCT_ALIPAY_SCAN],
                    ['label' => '支付宝JSAPI', 'value' => self::PRODUCT_ALIPAY_JSAPI],
                    ['label' => '微信扫码', 'value' => self::PRODUCT_WXPAY_SCAN],
                    ['label' => '微信JSAPI', 'value' => self::PRODUCT_WXPAY_JSAPI],
                    ['label' => '云闪付扫码', 'value' => self::PRODUCT_BANK_SCAN],
                ],
                'validate' => [
                    ['required' => true, 'message' => '已开通产品不能为空'],
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'sandbox',
                'title' => '测试环境',
                'value' => false,
                'props' => [
                    'checkedText' => '测试',
                    'uncheckedText' => '生产',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_base_url',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用天阙默认网关',
                ],
            ],
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
        $method = (string) ($order['extra']['payment']['method'] ?? '');

        if ($payType === 'alipay' && $method === 'jsapi') {
            return $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY');
        }
        if ($payType === 'wxpay' && $method === 'jsapi') {
            return $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, 'WECHAT');
        }
        if ($payType === 'bank') {
            return $this->scanPay($order, self::PRODUCT_BANK_SCAN, 'UNIONPAY');
        }
        if ($payType === 'wxpay') {
            return $this->scanPay($order, self::PRODUCT_WXPAY_SCAN, 'WECHAT');
        }

        return $this->scanPay($order, self::PRODUCT_ALIPAY_SCAN, 'ALIPAY');
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
            $data = $this->client()->submit('/query/tradeQuery', [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
            ]);
        } catch (TianqueTechSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = $this->tradeStatus((string) ($data['tranSts'] ?? $data['bizCode'] ?? ''));

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['ordNo'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['sxfUuid'] ?? $data['transactionId'] ?? $order['chan_trade_no'] ?? $order['pay_no']),
            'channel_status' => (string) ($data['tranSts'] ?? $data['bizCode'] ?? ''),
            'message' => (string) ($data['bizMsg'] ?? $data['tranSts'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['payTime'] ?? null) : null,
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
            $data = $this->client()->submit('/query/cancel', [
                'mno' => $this->configText('merchant_no'),
                'origOrderNo' => (string) $order['pay_no'],
            ]);
        } catch (TianqueTechSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = (string) ($data['tranSts'] ?? '') === 'CANCELED' || (string) ($data['bizCode'] ?? '') === '0000';

        return [
            'success' => $ok,
            'msg' => $ok ? '关单成功' : (string) ($data['bizMsg'] ?? '关单失败'),
            'raw_data' => $data,
        ];
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
            $data = $this->client()->submit('/order/refund', [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['refund_no'],
                'origOrderNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (TianqueTechSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = (string) ($data['bizCode'] ?? '') === '0000';

        return [
            'success' => $ok,
            'msg' => $ok ? '退款申请成功' : (string) ($data['bizMsg'] ?? '退款失败'),
            'chan_refund_no' => (string) ($data['ordNo'] ?? $order['refund_no']),
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
        if ($payload === [] || !$this->client()->verify($payload)) {
            throw new PaymentException('天阙回调验签失败', 40200);
        }

        $outTradeNo = (string) ($payload['ordNo'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('天阙回调缺少订单号', 40200);
        }

        $status = (string) ($payload['bizCode'] ?? '') === '0000'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::FAILED;

        return [
            'status' => $status,
            'message' => (string) ($payload['bizMsg'] ?? $payload['bizCode'] ?? ''),
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => (string) ($payload['sxfUuid'] ?? $payload['transactionId'] ?? $outTradeNo),
            'channel_status' => (string) ($payload['bizCode'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($payload['payTime'] ?? null) : null,
        ];
    }

    /**
     * 返回天阙成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '{"code":"success","msg":"成功"}';
    }

    /**
     * 返回天阙失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '{"code":"fail","msg":"失败"}';
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 天阙支付类型
     * @return array<string, mixed>
     */
    private function scanPay(array $order, string $product, string $payType): array
    {
        $this->ensureProduct($product);

        try {
            $data = $this->client()->submit('/order/activeScan', [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['amount']),
                'payType' => $payType,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentException('天阙扫码下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['bizCode'] ?? '') !== '0000') {
            throw new PaymentException('天阙扫码下单失败：' . (string) ($data['bizMsg'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        $url = (string) ($data['payUrl'] ?? '');
        if ($url === '') {
            throw new PaymentException('天阙扫码下单未返回支付地址', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'activeScan',
            'pay_params' => [
                'qrcode' => $url,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['sxfUuid'] ?? ''),
        ];
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 天阙支付类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $this->ensureProduct($product);

        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('JSAPI支付缺少用户标识', 40200);
        }

        try {
            $data = $this->client()->submit('/order/jsapiScan', [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['amount']),
                'payType' => $payType,
                'payWay' => '02',
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'subAppid' => (string) ($payment['sub_appid'] ?? ''),
                'userId' => $userId,
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (TianqueTechSdkException $e) {
            throw new PaymentException('天阙JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['bizCode'] ?? '') !== '0000') {
            throw new PaymentException('天阙JSAPI下单失败：' . (string) ($data['bizMsg'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        $params = $payType === 'WECHAT'
            ? [
                'appId' => (string) ($data['payAppId'] ?? ''),
                'timeStamp' => (string) ($data['payTimeStamp'] ?? ''),
                'nonceStr' => (string) ($data['paynonceStr'] ?? ''),
                'package' => (string) ($data['payPackage'] ?? ''),
                'signType' => (string) ($data['paySignType'] ?? ''),
                'paySign' => (string) ($data['paySign'] ?? ''),
            ]
            : [
                'tradeNO' => (string) ($data['source'] ?? ''),
            ];
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'jsapiScan',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['sxfUuid'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): TianqueTechClient
    {
        if ($this->client === null) {
            $this->client = new TianqueTechClient([
                'org_id' => $this->configText('org_id'),
                'merchant_no' => $this->configText('merchant_no'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'sandbox' => $this->configBool('sandbox'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前天阙通道未开启该支付产品', 40200, ['product' => $product]);
        }
    }

    /**
     * 获取启用产品列表。
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
        if (!is_array($products)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $products
        )));
    }

    /**
     * 映射查单状态。
     */
    private function tradeStatus(string $status): string
    {
        $status = strtoupper($status);

        return match ($status) {
            'SUCCESS', '0000' => PaymentPluginStatusConstant::SUCCESS,
            'CANCELED', 'CLOSED', 'FAILED', 'FAIL' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    /**
     * 获取布尔配置。
     */
    private function configBool(string $key): bool
    {
        return in_array($this->getConfig($key, false), [true, 1, '1', 'true', 'on'], true);
    }
}
