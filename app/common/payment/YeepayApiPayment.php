<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\yeepay\YeepaySdkException;
use app\common\sdk\yeepay\YeepayYopClient;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 易宝聚合支付插件。
 *
 * 迁移彩虹 `yeepay` 的聚合预下单、托管 H5、回调解密和退款主链路。
 */
class YeepayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_WXPAY_H5 = 'wxpay_h5';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private ?YeepayYopClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'yeepay_api',
        'name' => '易宝聚合支付',
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
                'field' => 'app_key',
                'title' => '应用标识',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '应用标识不能为空'],
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
                'type' => 'input',
                'field' => 'parent_merchant_no',
                'title' => '发起方商户编号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '发起方商户编号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '收款商户编号',
                'value' => '',
                'props' => [
                    'placeholder' => '留空则与发起方商户编号一致',
                ],
            ],
            [
                'type' => 'select',
                'field' => 'scene',
                'title' => '支付场景',
                'value' => 'ONLINE',
                'options' => [
                    ['label' => '线上', 'value' => 'ONLINE'],
                    ['label' => '线下', 'value' => 'OFFLINE'],
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
                    ['label' => '微信托管H5', 'value' => self::PRODUCT_WXPAY_H5],
                    ['label' => '云闪付扫码', 'value' => self::PRODUCT_BANK_SCAN],
                ],
                'validate' => [
                    ['required' => true, 'message' => '已开通产品不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_base_url',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用易宝默认网关',
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
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY_LIFE', 'ALIPAY'),
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, $this->wxpayJsapiPayWay($order), 'WECHAT'),
                    };
                },
            ],
            'h5' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],

                'handler' => fn (): array => $this->tutelagePay($order),
            ],
            'jump' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_H5],

                'handler' => fn (): array => $this->tutelagePay($order),
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],

                'handler' => fn (): array => $this->scanPayByType($order, $payType),
            ],
        ], '易宝');
    }

    /**
     * 按支付方式选择易宝扫码产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function scanPayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->scanPay($order, self::PRODUCT_BANK_SCAN, 'USER_SCAN', 'UNIONPAY'),
            'wxpay' => $this->scanPay($order, self::PRODUCT_WXPAY_SCAN, 'USER_SCAN', 'WECHAT'),
            default => $this->scanPay($order, self::PRODUCT_ALIPAY_SCAN, 'USER_SCAN', 'ALIPAY'),
        };
    }

    /**
     * 易宝旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => '易宝插件暂不支持主动查单',
        ];
    }

    /**
     * 易宝旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '易宝插件暂不支持关单',
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
            $data = $this->client()->post('/rest/v1.0/trade/refund', [
                'parentMerchantNo' => $this->configText('parent_merchant_no'),
                'merchantNo' => $this->merchantNo(),
                'orderId' => (string) $order['pay_no'],
                'refundRequestId' => (string) $order['refund_no'],
                'refundAmount' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (YeepaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = (string) ($data['code'] ?? '') === 'OPR00000';

        return [
            'success' => $ok,
            'msg' => $ok ? '退款申请成功' : '[' . (string) ($data['code'] ?? '') . ']' . (string) ($data['message'] ?? '退款失败'),
            'chan_refund_no' => (string) ($data['uniqueRefundNo'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['refundAmount'] ?? 0)) * 100),
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
        $response = (string) ($request->post('response') ?? '');
        if ($response === '') {
            throw new PaymentException('易宝回调缺少 response', 40200);
        }

        try {
            $data = $this->client()->notifyDecrypt($response);
        } catch (YeepaySdkException $e) {
            throw new PaymentException('易宝回调解密失败：' . $e->getMessage(), 40200);
        }

        $success = (string) ($data['status'] ?? '') === 'SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($data['status'] ?? ''),
            'channel_order_no' => (string) ($data['orderId'] ?? ''),
            'channel_trade_no' => (string) ($data['uniqueOrderNo'] ?? $data['channelTrxId'] ?? ''),
            'channel_status' => (string) ($data['status'] ?? ''),
        ];
    }

    /**
     * 返回易宝成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回易宝失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'FAIL';
    }

    /**
     * 扫码预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payWay 易宝 payWay
     * @param string $channel 易宝 channel
     * @return array<string, mixed>
     */
    private function scanPay(array $order, string $product, string $payWay, string $channel): array
    {
        $data = $this->requestPrePay($order, $product, $payWay, $channel);
        $qrcode = (string) ($data['prePayTn'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('易宝扫码下单未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'aggpay.pre-pay',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['uniqueOrderNo'] ?? ''),
        ];
    }

    /**
     * JSAPI 预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payWay 易宝 payWay
     * @param string $channel 易宝 channel
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $payWay, string $channel): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['mini_openid'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('易宝JSAPI支付缺少用户标识', 40200);
        }

        $data = $this->requestPrePay($order, $product, $payWay, $channel, (string) ($payment['sub_appid'] ?? ''), $userId);
        $prePayTn = (string) ($data['prePayTn'] ?? '');
        $params = json_decode($prePayTn, true);
        if (!is_array($params)) {
            $params = ['tradeNO' => $prePayTn];
        }
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'aggpay.pre-pay',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['uniqueOrderNo'] ?? ''),
        ];
    }

    /**
     * 微信托管 H5。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function tutelagePay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_WXPAY_H5);
        $params = $this->baseOrder($order) + [
            'payWay' => 'H5_PAY',
            'channel' => 'WECHAT',
        ];

        try {
            $data = $this->client()->post('/rest/v1.0/aggpay/tutelage/pre-pay', $params);
        } catch (YeepaySdkException $e) {
            throw new PaymentException('易宝托管支付下单失败：' . $e->getMessage(), 40200);
        }
        if ((string) ($data['code'] ?? '') !== '00000') {
            throw new PaymentException('易宝托管支付下单失败：[' . (string) ($data['code'] ?? '') . ']' . (string) ($data['message'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_WXPAY_H5,
            'pay_action' => 'aggpay.tutelage.pre-pay',
            'pay_params' => [
                'url' => (string) ($data['prePayTn'] ?? ''),
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['uniqueOrderNo'] ?? ''),
        ];
    }

    /**
     * 请求易宝聚合预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payWay 易宝 payWay
     * @param string $channel 易宝 channel
     * @param string $appId 子应用 ID
     * @param string $userId 用户标识
     * @return array<string, mixed>
     */
    private function requestPrePay(array $order, string $product, string $payWay, string $channel, string $appId = '', string $userId = ''): array
    {
        $this->ensureProduct($product);
        $params = $this->baseOrder($order) + [
            'payWay' => $payWay,
            'channel' => $channel,
        ];
        if ($appId !== '') {
            $params['appId'] = $appId;
        }
        if ($userId !== '') {
            $params['userId'] = $userId;
        }

        try {
            $data = $this->client()->post('/rest/v1.0/aggpay/pre-pay', $params);
        } catch (YeepaySdkException $e) {
            throw new PaymentException('易宝下单失败：' . $e->getMessage(), 40200);
        }
        if ((string) ($data['code'] ?? '') !== '00000') {
            throw new PaymentException('易宝下单失败：[' . (string) ($data['code'] ?? '') . ']' . (string) ($data['message'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        return $data;
    }

    /**
     * 微信 JSAPI 与小程序共用一个已开通产品，按本次订单身份选择易宝 payWay。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function wxpayJsapiPayWay(array $order): string
    {
        $payment = (array) ($order['extra']['payment'] ?? []);

        return (string) ($payment['mini_openid'] ?? '') !== '' ? 'MINI_PROGRAM' : 'WECHAT_OFFIACCOUNT';
    }

    /**
     * 构造基础订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function baseOrder(array $order): array
    {
        return [
            'parentMerchantNo' => $this->configText('parent_merchant_no'),
            'merchantNo' => $this->merchantNo(),
            'orderId' => (string) $order['pay_no'],
            'orderAmount' => FormatHelper::amount((int) $order['amount']),
            'goodsName' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notifyUrl' => (string) $order['callback_url'],
            'redirectUrl' => (string) $order['return_url'],
            'scene' => $this->configText('scene') ?: 'ONLINE',
            'userIp' => (string) $order['client_ip'],
        ];
    }

    /**
     * 获取易宝客户端。
     */
    private function client(): YeepayYopClient
    {
        if ($this->client === null) {
            $this->client = new YeepayYopClient([
                'app_key' => $this->configText('app_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 收款商户号，未配置时使用发起方商户号。
     */
    private function merchantNo(): string
    {
        $merchantNo = $this->configText('merchant_no');

        return $merchantNo !== '' ? $merchantNo : $this->configText('parent_merchant_no');
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前易宝通道未开启该支付产品', 40200, ['product' => $product]);
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
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
