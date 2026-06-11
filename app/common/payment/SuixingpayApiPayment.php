<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\suixingpay\SuixingpayClient;
use app\common\sdk\suixingpay\SuixingpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 随行付 OpenAPI 支付插件。
 *
 * 虽然彩虹旧插件的接口形态与天阙相近，但随行付作为独立收单产品维护，
 * SDK、配置项和插件类都单独命名，便于后续按官方文档继续演进。
 */
class SuixingpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private ?SuixingpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'suixingpay_api',
        'name' => '随行付OpenAPI支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
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
                    ['label' => '微信JSAPI/小程序', 'value' => self::PRODUCT_WXPAY_JSAPI],
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
                    'placeholder' => '留空使用随行付默认网关',
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

        return $this->executeDirectPaymentProduct($order, [
            'jsapi' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI, 'wxpay' => self::PRODUCT_WXPAY_JSAPI],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY'),
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, 'WECHAT'),
                    };
                },
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],

                'handler' => fn (): array => $this->scanPayByType($order, $payType),
            ],
        ], '随行付');
    }

    /**
     * 按支付方式选择随行付扫码产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function scanPayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->scanPay($order, self::PRODUCT_BANK_SCAN, 'UNIONPAY'),
            'wxpay' => $this->scanPay($order, self::PRODUCT_WXPAY_SCAN, 'WECHAT'),
            default => $this->scanPay($order, self::PRODUCT_ALIPAY_SCAN, 'ALIPAY'),
        };
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
        } catch (SuixingpaySdkException $e) {
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
        } catch (SuixingpaySdkException $e) {
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
        } catch (SuixingpaySdkException $e) {
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
            throw new PaymentException('随行付回调验签失败', 40200);
        }

        $outTradeNo = (string) ($payload['ordNo'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('随行付回调缺少订单号', 40200);
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
     * 返回随行付成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '{"code":"success","msg":"成功"}';
    }

    /**
     * 返回随行付失败应答。
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
     * @param string $payType 随行付支付类型
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
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付扫码下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['bizCode'] ?? '') !== '0000') {
            throw new PaymentException('随行付扫码下单失败：' . (string) ($data['bizMsg'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        $url = (string) ($data['payUrl'] ?? '');
        if ($url === '') {
            throw new PaymentException('随行付扫码下单未返回支付地址', 40200, ['response' => $data]);
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
     * @param string $payType 随行付支付类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $this->ensureProduct($product);

        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['mini_openid'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('随行付JSAPI支付缺少用户标识', 40200);
        }
        $payWay = $payType === 'WECHAT' && (string) ($payment['mini_openid'] ?? '') !== '' ? '03' : '02';

        try {
            $data = $this->client()->submit('/order/jsapiScan', [
                'mno' => $this->configText('merchant_no'),
                'ordNo' => (string) $order['pay_no'],
                'amt' => FormatHelper::amount((int) $order['amount']),
                'payType' => $payType,
                'payWay' => $payWay,
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'trmIp' => (string) $order['client_ip'],
                'subAppid' => (string) ($payment['sub_appid'] ?? ''),
                'userId' => $userId,
                'notifyUrl' => (string) $order['callback_url'],
            ]);
        } catch (SuixingpaySdkException $e) {
            throw new PaymentException('随行付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['bizCode'] ?? '') !== '0000') {
            throw new PaymentException('随行付JSAPI下单失败：' . (string) ($data['bizMsg'] ?? '渠道返回失败'), 40200, [
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
    private function client(): SuixingpayClient
    {
        if ($this->client === null) {
            $this->client = new SuixingpayClient([
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
            throw new PaymentException('当前随行付通道未开启该支付产品', 40200, ['product' => $product]);
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
