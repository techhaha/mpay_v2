<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\fubei\FubeiClient;
use app\common\sdk\fubei\FubeiSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 付呗开放接口支付插件。
 *
 * 彩虹旧插件部分页面依赖自身 OAuth 中转页。MPAY 版本只保留可直接由插件调用的
 * 支付产品；需要用户身份的 JSAPI 场景必须由入口层传入 openid/buyer_id。
 */
class FubeiApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private ?FubeiClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'fubei_api',
        'name' => '付呗开放接口支付',
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
                'field' => 'app_id',
                'title' => '开放平台ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '开放平台ID不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'app_secret',
                'title' => '接口密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '接口密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_id',
                'title' => '商户ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户ID不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'store_id',
                'title' => '门店ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '门店ID不能为空'],
                ],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通产品',
                'value' => [self::PRODUCT_ALIPAY_H5, self::PRODUCT_BANK_SCAN],
                'options' => [
                    ['label' => '支付宝H5', 'value' => self::PRODUCT_ALIPAY_H5],
                    ['label' => '支付宝JSAPI', 'value' => self::PRODUCT_ALIPAY_JSAPI],
                    ['label' => '微信JSAPI', 'value' => self::PRODUCT_WXPAY_JSAPI],
                    ['label' => '云闪付扫码', 'value' => self::PRODUCT_BANK_SCAN],
                ],
                'validate' => [
                    ['required' => true, 'message' => '已开通产品不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_gateway',
                'title' => '自定义网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用付呗默认网关',
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
                'products' => ['wxpay' => self::PRODUCT_WXPAY_JSAPI, 'alipay' => self::PRODUCT_ALIPAY_JSAPI],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, 'wxpay'),
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'alipay'),
                    };
                },
            ],
            'h5' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],

                'handler' => fn (): array => $this->alipayH5($order),
            ],
            'jump' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],

                'handler' => fn (): array => $this->alipayH5($order),
            ],
            'web' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],

                'handler' => fn (): array => $this->alipayH5($order),
            ],
            'qrcode' => [
                'products' => ['bank' => self::PRODUCT_BANK_SCAN],

                'handler' => fn (): array => $this->createOrder($order, self::PRODUCT_BANK_SCAN, 'unionpay'),
            ],
        ], '付呗');
    }

    /**
     * 付呗旧插件未提供独立查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => '付呗插件暂不支持主动查单',
        ];
    }

    /**
     * 付呗旧插件未提供关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '付呗插件暂不支持关单',
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
            $data = $this->client()->execute('fbpay.order.refund', [
                'order_sn' => (string) ($order['chan_trade_no'] ?? ''),
                'merchant_refund_sn' => (string) $order['refund_no'],
                'refund_amount' => FormatHelper::amount((int) $order['refund_amount']),
            ]);

            return [
                'success' => true,
                'msg' => '退款申请成功',
                'chan_refund_no' => (string) ($data['merchant_refund_sn'] ?? $order['refund_no']),
                'raw_data' => $data,
            ];
        } catch (FubeiSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * 解析支付回调。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = $request->all();
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('付呗回调验签失败', 40200);
        }

        $data = json_decode((string) ($payload['data'] ?? ''), true);
        if (!is_array($data)) {
            throw new PaymentException('付呗回调 data 不是合法 JSON', 40200);
        }

        $status = (string) ($data['order_status'] ?? '') === 'SUCCESS'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;
        $outTradeNo = (string) ($data['merchant_order_sn'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('付呗回调缺少商户订单号', 40200);
        }

        return [
            'status' => $status,
            'message' => (string) ($data['order_status'] ?? ''),
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => (string) ($data['order_sn'] ?? $outTradeNo),
            'channel_status' => (string) ($data['order_status'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['pay_time'] ?? null) : null,
        ];
    }

    /**
     * 返回付呗成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回付呗失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 支付宝 H5 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function alipayH5(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_ALIPAY_H5);

        try {
            $data = $this->client()->execute('fbpay.order.wap.create', $this->baseOrder($order) + [
                'user_ip' => (string) $order['client_ip'],
                'return_url' => (string) $order['return_url'],
            ]);
        } catch (FubeiSdkException $e) {
            throw new PaymentException('付呗支付宝H5下单失败：' . $e->getMessage(), 40200);
        }

        $html = (string) ($data['html'] ?? '');
        if ($html === '') {
            throw new PaymentException('付呗支付宝H5未返回支付内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => str_starts_with($html, 'http') ? 'jump' : 'html',
            'pay_type' => 'alipay',
            'pay_product' => self::PRODUCT_ALIPAY_H5,
            'pay_action' => 'fbpay.order.wap.create',
            'pay_params' => str_starts_with($html, 'http')
                ? ['url' => $html, 'raw' => $data]
                : ['html' => $html, 'raw' => $data],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['order_sn'] ?? ''),
        ];
    }

    /**
     * JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 付呗支付类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('付呗JSAPI支付缺少用户标识', 40200);
        }

        return $this->createOrder($order, $product, $payType, $userId, (string) ($payment['sub_appid'] ?? ''));
    }

    /**
     * 通用下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 付呗支付类型
     * @param string $userId 用户标识
     * @param string $subAppId 子应用 ID
     * @return array<string, mixed>
     */
    private function createOrder(array $order, string $product, string $payType, string $userId = '', string $subAppId = ''): array
    {
        $this->ensureProduct($product);

        $payload = $this->baseOrder($order) + [
            'pay_type' => $payType,
            'user_id' => $userId,
        ];
        if ($subAppId !== '') {
            $payload['sub_appid'] = $subAppId;
        }

        try {
            $data = $this->client()->execute('fbpay.order.create', $payload);
        } catch (FubeiSdkException $e) {
            throw new PaymentException('付呗下单失败：' . $e->getMessage(), 40200);
        }

        if ($product === self::PRODUCT_BANK_SCAN) {
            $qrcode = (string) ($data['qr_code'] ?? $data['pay_url'] ?? $data['code_url'] ?? $data['prepay_id'] ?? '');
            if ($qrcode === '') {
                throw new PaymentException('付呗扫码下单未返回二维码内容', 40200, ['response' => $data]);
            }

            return [
                'pay_page' => 'qrcode',
                'pay_type' => (string) $order['pay_type_code'],
                'pay_product' => $product,
                'pay_action' => 'fbpay.order.create',
                'pay_params' => [
                    'qrcode' => $qrcode,
                    'raw' => $data,
                ],
                'chan_order_no' => (string) $order['pay_no'],
                'chan_trade_no' => (string) ($data['order_sn'] ?? ''),
            ];
        }

        $params = (array) ($data['sign_package'] ?? []);
        if ($params === [] && isset($data['prepay_id'])) {
            $params = ['tradeNO' => (string) $data['prepay_id']];
        }
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'fbpay.order.create',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['order_sn'] ?? ''),
        ];
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
            'merchant_id' => $this->configText('merchant_id'),
            'merchant_order_sn' => (string) $order['pay_no'],
            'total_amount' => FormatHelper::amount((int) $order['amount']),
            'store_id' => $this->configText('store_id'),
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): FubeiClient
    {
        if ($this->client === null) {
            $this->client = new FubeiClient([
                'app_id' => $this->configText('app_id'),
                'app_secret' => $this->configText('app_secret'),
                'api_gateway' => $this->configText('api_gateway'),
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
            throw new PaymentException('当前付呗通道未开启该支付产品', 40200, ['product' => $product]);
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
