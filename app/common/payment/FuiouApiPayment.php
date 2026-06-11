<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\fuiou\FuiouPayClient;
use app\common\sdk\fuiou\FuiouSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 富友合作方聚合支付插件。
 *
 * 迁移自彩虹易支付 `fuiou2` 插件，只保留支付主链路：
 * 扫码预下单、JSAPI、付款码、回调、查单、关单和退款。
 */
class FuiouApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_BARCODE = 'barcode';
    private const UPSTREAM_WXPAY_JSAPI = 'JSAPI';
    private const UPSTREAM_WXPAY_MINI = 'LETPAY';

    private ?FuiouPayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'fuiou_api',
        'name' => '富友合作方聚合支付',
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
                'field' => 'institution_code',
                'title' => '机构号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '机构号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户号不能为空'],
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
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '富友公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [
                    ['required' => true, 'message' => '富友公钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'order_prefix',
                'title' => '订单号前缀',
                'value' => '',
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
                    ['label' => '付款码支付', 'value' => self::PRODUCT_BARCODE],
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
                    'placeholder' => '留空使用富友默认网关',
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
        $payType = $this->payTypeCode($order);

        return $this->executeDirectPaymentProduct($order, [
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_BARCODE,
                    'wxpay' => self::PRODUCT_BARCODE,
                    'bank' => self::PRODUCT_BARCODE,
                ],

                'handler' => fn (): array => $this->barcodePay(
                    $order,
                    $this->orderType($payType),
                    (string) ($order['extra']['payment']['auth_code'] ?? '')
                ),
            ],
            'jsapi' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI, 'wxpay' => self::PRODUCT_WXPAY_JSAPI],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'FWC'),
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, $this->wxpayJsapiTradeType($order)),
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
        ], '富友');
    }

    /**
     * 按支付方式选择富友扫码产品。
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
     * 微信 JSAPI 和小程序共用旧插件的同一个开通项，实际接口产品由身份字段区分。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function wxpayJsapiTradeType(array $order): string
    {
        $payment = (array) ($order['extra']['payment'] ?? []);

        return (string) ($payment['mini_openid'] ?? '') !== ''
            ? self::UPSTREAM_WXPAY_MINI
            : self::UPSTREAM_WXPAY_JSAPI;
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
            $data = $this->client()->submit('/commonQuery', [
                'order_type' => $this->orderType($this->payTypeCode($order)),
                'mchnt_order_no' => $this->channelOrderNo($order),
            ]);
        } catch (FuiouSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = $this->tradeStatus((string) ($data['trans_stat'] ?? $data['tranSts'] ?? ''));

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['mchnt_order_no'] ?? $this->channelOrderNo($order)),
            'channel_trade_no' => (string) ($data['transaction_id'] ?? $data['orderNo'] ?? $order['chan_trade_no'] ?? ''),
            'channel_status' => (string) ($data['trans_stat'] ?? $data['tranSts'] ?? ''),
            'message' => (string) ($data['result_msg'] ?? $data['trans_stat'] ?? $data['tranSts'] ?? ''),
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
            $data = $this->client()->submit('/cancelorder', [
                'order_type' => $this->orderType($this->payTypeCode($order)),
                'mchnt_order_no' => $this->channelOrderNo($order),
                'cancel_order_no' => date('YmdHis') . random_int(1000, 9999),
                'operator_id' => '',
            ]);
        } catch (FuiouSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = (string) ($data['result_code'] ?? '') === '000000';

        return [
            'success' => $ok,
            'msg' => $ok ? '关单成功' : (string) ($data['result_msg'] ?? '关单失败'),
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
            $data = $this->client()->submit('/commonRefund', [
                'mchnt_order_no' => (string) ($order['chan_order_no'] ?? $order['out_trade_no'] ?? $order['pay_no']),
                'refund_order_no' => (string) $order['refund_no'],
                'order_type' => $this->orderType($this->payTypeCode($order)),
                'total_amt' => (string) (int) ($order['pay_amount'] ?? $order['amount'] ?? $order['refund_amount']),
                'refund_amt' => (string) (int) $order['refund_amount'],
                'operator_id' => '',
            ]);
        } catch (FuiouSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refund_order_no'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['reserved_refund_amt'] ?? $order['refund_amount']),
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
        $xml = urldecode((string) ($request->post('req') ?? ''));
        $payload = $this->client()->parseXml($xml);
        if (!$this->client()->verifyNotify($payload)) {
            throw new PaymentException('富友回调验签失败', 40200);
        }

        $outTradeNo = (string) ($payload['mchnt_order_no'] ?? '');
        if ($outTradeNo === '') {
            throw new PaymentException('富友回调缺少商户订单号', 40200);
        }

        $status = (string) ($payload['result_code'] ?? '') === '000000'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::FAILED;

        return [
            'status' => $status,
            'message' => (string) ($payload['result_msg'] ?? $payload['result_code'] ?? ''),
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => (string) ($payload['transaction_id'] ?? $outTradeNo),
            'channel_status' => (string) ($payload['result_code'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($payload['txn_fin_ts'] ?? null) : null,
        ];
    }

    /**
     * 返回富友成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '1';
    }

    /**
     * 返回富友失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '0';
    }

    /**
     * 扫码预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $orderType 富友订单类型
     * @return array<string, mixed>
     */
    private function scanPay(array $order, string $product, string $orderType): array
    {
        $this->ensureProduct($product);

        try {
            $data = $this->client()->submit('/preCreate', $this->baseOrder($order) + [
                'order_type' => $orderType,
            ]);
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友扫码下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['qr_code'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('富友扫码下单未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => $this->payTypeCode($order),
            'pay_product' => $product,
            'pay_action' => 'preCreate',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ];
    }

    /**
     * JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $tradeType 富友交易类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $tradeType): array
    {
        $this->ensureProduct($product);

        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['mini_openid'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('富友JSAPI支付缺少用户标识', 40200);
        }

        try {
            $data = $this->client()->submit('/wxPreCreate', $this->baseOrder($order) + [
                'trade_type' => $tradeType,
                'limit_pay' => '',
                'product_id' => '',
                'openid' => '',
                'sub_openid' => $userId,
                'sub_appid' => (string) ($payment['sub_appid'] ?? ''),
            ]);
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $params = $tradeType === 'FWC'
            ? ['tradeNO' => (string) ($data['reserved_transaction_id'] ?? '')]
            : [
                'appId' => (string) ($data['sdk_appid'] ?? ''),
                'timeStamp' => (string) ($data['sdk_timestamp'] ?? ''),
                'nonceStr' => (string) ($data['sdk_noncestr'] ?? ''),
                'package' => (string) ($data['sdk_package'] ?? ''),
                'signType' => (string) ($data['sdk_signtype'] ?? ''),
                'paySign' => (string) ($data['sdk_paysign'] ?? ''),
            ];
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => $this->payTypeCode($order),
            'pay_product' => $product,
            'pay_action' => 'wxPreCreate',
            'pay_params' => $params,
            'chan_order_no' => $this->channelOrderNo($order),
            'chan_trade_no' => (string) ($data['reserved_transaction_id'] ?? $data['transaction_id'] ?? ''),
        ];
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $orderType 富友订单类型
     * @param string $authCode 付款码
     * @return array<string, mixed>
     */
    private function barcodePay(array $order, string $orderType, string $authCode): array
    {
        $this->ensureProduct(self::PRODUCT_BARCODE);

        try {
            $data = $this->client()->submit('/micropay', $this->baseOrder($order) + [
                'order_type' => $orderType,
                'auth_code' => $authCode,
                'sence' => '1',
            ]);
        } catch (FuiouSdkException $e) {
            throw new PaymentException('富友付款码下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['result_code'] ?? '') === '030010') {
            return $this->waitBarcodePayment($order, $orderType, $data);
        }

        return $this->barcodeResult($order, $data, '支付成功');
    }

    /**
     * 等待付款码用户确认。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $orderType 富友订单类型
     * @param array<string, mixed> $rawData 原始下单结果
     * @return array<string, mixed>
     */
    private function waitBarcodePayment(array $order, string $orderType, array $rawData): array
    {
        for ($index = 0; $index < 6; $index++) {
            sleep(3);
            try {
                $query = $this->client()->submit('/commonQuery', [
                    'order_type' => $orderType,
                    'mchnt_order_no' => $this->channelOrderNo($order),
                ]);
            } catch (FuiouSdkException $e) {
                throw new PaymentException('富友付款码查单失败：' . $e->getMessage(), 40200);
            }
            $status = (string) ($query['trans_stat'] ?? $query['tranSts'] ?? '');
            if ($status === 'SUCCESS') {
                return $this->barcodeResult($order, $query, '支付成功');
            }
            if (!in_array($status, ['USERPAYING', 'NOTPAY'], true)) {
                throw new PaymentException('富友付款码支付失败：订单超时或用户取消支付', 40200, ['response' => $query]);
            }
        }

        $this->close($order);

        throw new PaymentException('富友付款码支付失败：等待用户确认超时', 40200, ['response' => $rawData]);
    }

    /**
     * 构造付款码承接结果。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, mixed> $data 渠道结果
     * @param string $message 页面提示
     * @return array<string, mixed>
     */
    private function barcodeResult(array $order, array $data, string $message): array
    {
        return [
            'pay_page' => 'ok',
            'pay_type' => $this->payTypeCode($order),
            'pay_product' => self::PRODUCT_BARCODE,
            'pay_action' => 'micropay',
            'pay_params' => [
                'message' => $message,
                'raw' => $data,
            ],
            'chan_order_no' => (string) ($data['mchnt_order_no'] ?? $this->channelOrderNo($order)),
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ];
    }

    /**
     * 构造富友基础订单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function baseOrder(array $order): array
    {
        return [
            'order_amt' => (string) (int) $order['amount'],
            'mchnt_order_no' => $this->channelOrderNo($order),
            'txn_begin_ts' => date('YmdHis'),
            'goods_des' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'goods_detail' => '',
            'term_ip' => (string) $order['client_ip'],
            'notify_url' => (string) $order['callback_url'],
            'addn_inf' => '',
            'curr_type' => 'CNY',
            'goods_tag' => '',
        ];
    }

    /**
     * 获取富友客户端。
     */
    private function client(): FuiouPayClient
    {
        if ($this->client === null) {
            $this->client = new FuiouPayClient([
                'institution_code' => $this->configText('institution_code'),
                'merchant_no' => $this->configText('merchant_no'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'sandbox' => (bool) $this->getConfig('sandbox', false),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 生成富友商户订单号。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     */
    private function channelOrderNo(array $order): string
    {
        return (string) ($order['chan_order_no'] ?? '')
            ?: $this->configText('order_prefix') . (string) $order['pay_no'];
    }

    /**
     * 读取 MPAY 支付方式编码。
     *
     * @param array<string, mixed> $order 标准插件订单参数
     */
    private function payTypeCode(array $order): string
    {
        $extra = (array) ($order['extra'] ?? []);
        $presentation = (array) ($extra['presentation'] ?? []);

        return (string) ($order['pay_type_code'] ?? $presentation['pay_type'] ?? '');
    }

    /**
     * 将 MPAY 支付方式映射为富友订单类型。
     */
    private function orderType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNIONPAY',
            default => 'ALIPAY',
        };
    }

    /**
     * 将富友交易状态映射为 MPAY 状态。
     */
    private function tradeStatus(string $status): string
    {
        return match ($status) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'REVOKED', 'PAYERROR' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前富友通道未开启该支付产品', 40200, ['product' => $product]);
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
