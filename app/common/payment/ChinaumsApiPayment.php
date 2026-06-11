<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\chinaums\ChinaumsClient;
use app\common\sdk\chinaums\ChinaumsSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 银联商务开放平台支付插件。
 *
 * 第一阶段迁移彩虹 `chinaums` 的扫码、H5 跳转、回调和退款主链路。
 * APP、小程序预下单、分账等能力后续按独立产品继续补充。
 */
class ChinaumsApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_H5 = 'wxpay_h5';
    private const PRODUCT_WXPAY_MINI_H5 = 'wxpay_mini_h5';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private ?ChinaumsClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'chinaums_api',
        'name' => '银联商务开放平台支付',
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
                'title' => 'AppId',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'AppId不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'app_key',
                'title' => 'AppKey',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'AppKey不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户号mid',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'terminal_no',
                'title' => '终端号tid',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '终端号不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'communication_key',
                'title' => '通讯密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '通讯密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'msg_source_id',
                'title' => '来源编号',
                'value' => '',
                'props' => [
                    'placeholder' => '4位来源编号',
                ],
                'validate' => [
                    ['required' => true, 'message' => '来源编号不能为空'],
                ],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通产品',
                'value' => [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_WXPAY_SCAN, self::PRODUCT_BANK_SCAN],
                'options' => [
                    ['label' => '支付宝扫码', 'value' => self::PRODUCT_ALIPAY_SCAN],
                    ['label' => '支付宝H5', 'value' => self::PRODUCT_ALIPAY_H5],
                    ['label' => '微信扫码', 'value' => self::PRODUCT_WXPAY_SCAN],
                    ['label' => '微信H5', 'value' => self::PRODUCT_WXPAY_H5],
                    ['label' => '微信H5转小程序', 'value' => self::PRODUCT_WXPAY_MINI_H5],
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
                    'placeholder' => '留空使用银联商务默认网关',
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
            'h5' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5, 'wxpay' => self::PRODUCT_WXPAY_H5],

                'handler' => fn (): array => $this->h5PayByType($order, $payType, false),
            ],
            'jump' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5, 'wxpay' => self::PRODUCT_WXPAY_H5],

                'handler' => fn (): array => $this->h5PayByType($order, $payType, false),
            ],
            'web' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5, 'wxpay' => self::PRODUCT_WXPAY_H5],

                'handler' => fn (): array => $this->h5PayByType($order, $payType, false),
            ],
            'urlscheme' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_MINI_H5],

                'handler' => fn (): array => $this->h5PayByType($order, $payType, true),
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],

                'handler' => fn (): array => $this->qrcodePayByType($order, $payType),
            ],
        ], '银联商务');
    }

    /**
     * 按支付方式选择银联商务 H5 或小程序跳转产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @param bool $preferMini 是否优先微信 H5 转小程序
     * @return array<string, mixed>
     */
    private function h5PayByType(array $order, string $payType, bool $preferMini): array
    {
        return match ($payType) {
            'alipay' => $this->h5Pay($order, self::PRODUCT_ALIPAY_H5, '/v1/netpay/trade/h5-pay', 'alipay'),
            'wxpay' => $preferMini
                ? $this->h5Pay($order, self::PRODUCT_WXPAY_MINI_H5, '/v1/netpay/wxpay/h5-to-minipay', 'wxpay')
                : $this->h5Pay($order, self::PRODUCT_WXPAY_H5, '/v1/netpay/wxpay/h5-pay', 'wxpay'),
            default => throw new PaymentException('银联商务当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
        };
    }

    /**
     * 按支付方式选择银联商务扫码产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function qrcodePayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->qrcodePay($order, self::PRODUCT_BANK_SCAN),
            'wxpay' => $this->qrcodePay($order, self::PRODUCT_WXPAY_SCAN),
            default => $this->qrcodePay($order, self::PRODUCT_ALIPAY_SCAN),
        };
    }

    /**
     * 银联商务旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => '银联商务插件暂不支持主动查单',
        ];
    }

    /**
     * 银联商务旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '银联商务插件暂不支持关单',
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
        $presentation = (array) (($order['extra']['presentation'] ?? []) ?: []);
        $isH5 = str_contains((string) ($presentation['pay_product'] ?? ''), '_h5');
        $path = $isH5 ? '/v1/netpay/refund' : '/v1/netpay/bills/refund';
        $params = $this->baseRequest($order) + [
            'instMid' => $isH5 ? 'H5DEFAULT' : 'QRPAYDEFAULT',
            'billDate' => $this->orderDate($order),
            'refundOrderId' => $this->configText('msg_source_id') . (string) $order['refund_no'],
            'refundAmount' => (int) $order['refund_amount'],
        ];
        if ($isH5) {
            $params['merOrderId'] = (string) ($order['chan_order_no'] ?? $order['pay_no']);
        } else {
            $params['billNo'] = (string) ($order['chan_order_no'] ?? $order['pay_no']);
        }

        try {
            $data = $this->client()->request($path, $params);
        } catch (ChinaumsSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = (string) ($data['errCode'] ?? '') === 'SUCCESS';

        return [
            'success' => $ok,
            'msg' => $ok ? '退款申请成功' : (string) ($data['errMsg'] ?? $data['errInfo'] ?? '退款失败'),
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
        $payload = $request->post();
        if (!$this->client()->verifyNotify($payload)) {
            throw new PaymentException('银联商务回调验签失败', 40200);
        }

        $isH5 = (string) ($payload['instMid'] ?? '') === 'H5DEFAULT';
        $statusText = $isH5 ? (string) ($payload['status'] ?? '') : (string) ($payload['billStatus'] ?? '');
        $success = $isH5 ? $statusText === 'TRADE_SUCCESS' : $statusText === 'PAID';
        $billPayment = json_decode((string) ($payload['billPayment'] ?? '{}'), true);
        $billPayment = is_array($billPayment) ? $billPayment : [];
        $channelOrderNo = $isH5 ? (string) ($payload['merOrderId'] ?? '') : (string) ($payload['billNo'] ?? '');

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => $statusText,
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => (string) ($payload['targetOrderId'] ?? $billPayment['targetOrderId'] ?? $channelOrderNo),
            'channel_status' => $statusText,
        ];
    }

    /**
     * 返回银联商务成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回银联商务失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'FAILED';
    }

    /**
     * 二维码下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order, string $product): array
    {
        $this->ensureProduct($product);

        try {
            $data = $this->client()->request('/v1/netpay/bills/get-qrcode', $this->qrcodeOrder($order));
        } catch (ChinaumsSdkException $e) {
            throw new PaymentException('银联商务扫码下单失败：' . $e->getMessage(), 40200);
        }
        if ((string) ($data['errCode'] ?? '') !== 'SUCCESS') {
            throw new PaymentException('银联商务扫码下单失败：' . (string) ($data['errMsg'] ?? $data['errInfo'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        $qrcode = (string) ($data['billQRCode'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('银联商务扫码下单未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'get-qrcode',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => (string) ($data['targetOrderId'] ?? ''),
        ];
    }

    /**
     * H5 跳转下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $path 接口路径
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function h5Pay(array $order, string $product, string $path, string $payType): array
    {
        $this->ensureProduct($product);

        $params = $this->h5Order($order);
        if ($payType === 'wxpay') {
            $params['sceneType'] = 'AND_WAP';
            $params['merAppName'] = (string) (sys_config('site_name') ?: 'MPAY');
            $params['merAppId'] = rtrim((string) sys_config('site_url'), '/');
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'h5-pay',
            'pay_params' => [
                'url' => $this->client()->formUrl($path, $params),
            ],
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => '',
        ];
    }

    /**
     * 构造二维码订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function qrcodeOrder(array $order): array
    {
        return $this->baseRequest($order) + [
            'instMid' => 'QRPAYDEFAULT',
            'billNo' => $this->channelOrderNo($order),
            'billDate' => date('Y-m-d'),
            'billDesc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'totalAmount' => (int) $order['amount'],
            'notifyUrl' => (string) $order['callback_url'],
            'returnUrl' => (string) $order['return_url'],
            'clientIp' => (string) $order['client_ip'],
        ];
    }

    /**
     * 构造 H5 订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function h5Order(array $order): array
    {
        return $this->baseRequest($order) + [
            'instMid' => 'H5DEFAULT',
            'merOrderId' => $this->channelOrderNo($order),
            'orderDesc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'totalAmount' => (int) $order['amount'],
            'notifyUrl' => (string) $order['callback_url'],
            'returnUrl' => (string) $order['return_url'],
            'clientIp' => (string) $order['client_ip'],
        ];
    }

    /**
     * 构造银联商务公共请求字段。
     *
     * @param array<string, mixed> $order 标准插件参数
     * @return array<string, mixed>
     */
    private function baseRequest(array $order): array
    {
        return [
            'msgId' => md5(uniqid((string) mt_rand(), true)),
            'requestTimestamp' => date('Y-m-d H:i:s'),
            'mid' => $this->configText('merchant_no'),
            'tid' => $this->configText('terminal_no'),
        ];
    }

    /**
     * 获取银联商务客户端。
     */
    private function client(): ChinaumsClient
    {
        if ($this->client === null) {
            $this->client = new ChinaumsClient([
                'app_id' => $this->configText('app_id'),
                'app_key' => $this->configText('app_key'),
                'communication_key' => $this->configText('communication_key'),
                'sandbox' => (bool) $this->getConfig('sandbox', false),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 生成银联商务订单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     */
    private function channelOrderNo(array $order): string
    {
        return (string) ($order['chan_order_no'] ?? '')
            ?: $this->configText('msg_source_id') . (string) $order['pay_no'];
    }

    /**
     * 解析原支付单日期。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     */
    private function orderDate(array $order): string
    {
        $createdAt = (string) ($order['pay_created_at'] ?? '');
        if ($createdAt !== '') {
            return date('Y-m-d', strtotime($createdAt));
        }

        return date('Y-m-d');
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前银联商务通道未开启该支付产品', 40200, ['product' => $product]);
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
