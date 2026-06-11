<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\huifu\HuifuClient;
use app\common\sdk\huifu\HuifuSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 汇付斗拱平台支付插件。
 *
 * 第一阶段迁移彩虹 `huifu` 插件的支付主链路：
 * 斗拱聚合下单、付款码、异步回调、查单、关单和退款。
 */
class HuifuApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_ECNY_SCAN = 'ecny_scan';
    private const PRODUCT_BARCODE = 'barcode';

    private ?HuifuClient $client = null;

    /**
     * 通知成功应答中需要带回订单号。
     */
    private string $notifyAckOrderNo = '';

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'huifu_api',
        'name' => '汇付斗拱平台支付',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank', 'ecny'],
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
                'field' => 'sys_id',
                'title' => '汇付系统号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '汇付系统号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'product_id',
                'title' => '汇付产品号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '汇付产品号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'sub_merchant_no',
                'title' => '汇付子商户号',
                'value' => '',
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
                'field' => 'huifu_public_key',
                'title' => '汇付公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [
                    ['required' => true, 'message' => '汇付公钥不能为空'],
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
                    ['label' => '数字人民币扫码', 'value' => self::PRODUCT_ECNY_SCAN],
                    ['label' => '付款码支付', 'value' => self::PRODUCT_BARCODE],
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
                    'placeholder' => '留空使用汇付默认网关',
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
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_BARCODE,
                    'wxpay' => self::PRODUCT_BARCODE,
                    'bank' => self::PRODUCT_BARCODE,
                    'ecny' => self::PRODUCT_BARCODE,
                ],

                'handler' => fn (): array => $this->barcodePay($order, (string) ($order['extra']['payment']['auth_code'] ?? '')),
            ],
            'jsapi' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_JSAPI, 'wxpay' => self::PRODUCT_WXPAY_JSAPI],
                'handler' => function () use ($order, $payType): array {
                    return match ($payType) {
                        'alipay' => $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'A_JSAPI'),
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, $this->wxpayJsapiTradeType($order)),
                    };
                },
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'ecny' => self::PRODUCT_ECNY_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],

                'handler' => fn (): array => $this->scanPayByType($order, $payType),
            ],
        ], '汇付');
    }

    /**
     * 按支付方式选择汇付扫码产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function scanPayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->scanPay($order, self::PRODUCT_BANK_SCAN, 'U_NATIVE'),
            'ecny' => $this->scanPay($order, self::PRODUCT_ECNY_SCAN, 'D_NATIVE'),
            'wxpay' => $this->scanPay($order, self::PRODUCT_WXPAY_SCAN, 'T_NATIVE'),
            default => $this->scanPay($order, self::PRODUCT_ALIPAY_SCAN, 'A_NATIVE'),
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
            $data = $this->client()->request('/v3/trade/payment/scanpay/query', [
                'huifu_id' => $this->huifuId(),
                'org_hf_seq_id' => (string) ($order['chan_trade_no'] ?? $order['channel_trade_no'] ?? ''),
            ]);
        } catch (HuifuSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = $this->tradeStatus((string) ($data['trans_stat'] ?? ''));

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['org_req_seq_id'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['org_hf_seq_id'] ?? $data['hf_seq_id'] ?? $order['chan_trade_no'] ?? ''),
            'channel_status' => (string) ($data['trans_stat'] ?? ''),
            'message' => (string) ($data['resp_desc'] ?? $data['trans_stat'] ?? ''),
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
            $data = $this->client()->request('/v2/trade/payment/scanpay/close', [
                'req_date' => date('Ymd'),
                'req_seq_id' => date('YmdHis') . random_int(1000, 9999),
                'huifu_id' => $this->huifuId(),
                'org_req_date' => substr((string) $order['pay_no'], 0, 8),
                'org_req_seq_id' => (string) $order['pay_no'],
            ]);
        } catch (HuifuSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = in_array((string) ($data['resp_code'] ?? ''), ['00000000', '00000100'], true);

        return [
            'success' => $ok,
            'msg' => $ok ? '关单成功' : (string) ($data['resp_desc'] ?? '关单失败'),
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
            $data = $this->client()->request('/v3/trade/payment/scanpay/refund', [
                'req_date' => date('Ymd'),
                'req_seq_id' => (string) $order['refund_no'],
                'huifu_id' => $this->huifuId(),
                'ord_amt' => FormatHelper::amount((int) $order['refund_amount']),
                'org_req_date' => substr((string) $order['pay_no'], 0, 8),
                'org_req_seq_id' => (string) $order['pay_no'],
            ]);
        } catch (HuifuSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $ok = in_array((string) ($data['resp_code'] ?? ''), ['00000000', '00000100'], true);

        return [
            'success' => $ok,
            'msg' => $ok ? '退款申请成功' : (string) ($data['resp_desc'] ?? '退款失败'),
            'chan_refund_no' => (string) ($data['hf_seq_id'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['ord_amt'] ?? 0)) * 100),
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
        $respData = (string) ($request->post('resp_data') ?? '');
        $sign = (string) ($request->post('sign') ?? '');
        if (!$this->client()->verifyNotify($respData, $sign)) {
            throw new PaymentException('汇付回调验签失败', 40200);
        }

        $data = json_decode($respData, true);
        if (!is_array($data)) {
            throw new PaymentException('汇付回调数据不是合法 JSON', 40200);
        }

        $this->notifyAckOrderNo = (string) ($data['req_seq_id'] ?? '');
        $status = (string) ($data['trans_stat'] ?? '') === 'S'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::FAILED;

        return [
            'status' => $status,
            'message' => (string) ($data['resp_desc'] ?? $data['trans_stat'] ?? ''),
            'channel_order_no' => (string) ($data['req_seq_id'] ?? ''),
            'channel_trade_no' => (string) ($data['hf_seq_id'] ?? $data['out_trans_id'] ?? ''),
            'channel_status' => (string) ($data['trans_stat'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['trans_finish_time'] ?? null) : null,
        ];
    }

    /**
     * 返回汇付成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'RECV_ORD_ID_' . $this->notifyAckOrderNo;
    }

    /**
     * 返回汇付失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 扫码下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $tradeType 汇付交易类型
     * @return array<string, mixed>
     */
    private function scanPay(array $order, string $product, string $tradeType): array
    {
        $this->ensureProduct($product);
        $data = $this->requestJspay($order, $tradeType);

        $qrcode = (string) ($data['qr_code'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('汇付扫码下单未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'jspay',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['hf_seq_id'] ?? ''),
        ];
    }

    /**
     * JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $tradeType 汇付交易类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $tradeType): array
    {
        $this->ensureProduct($product);

        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['mini_openid'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('汇付JSAPI支付缺少用户标识', 40200);
        }

        $data = $this->requestJspay($order, $tradeType, $userId);
        $params = json_decode((string) ($data['pay_info'] ?? ''), true);
        if (!is_array($params)) {
            throw new PaymentException('汇付JSAPI下单未返回合法支付参数', 40200, ['response' => $data]);
        }
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'jspay',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['hf_seq_id'] ?? ''),
        ];
    }

    /**
     * 微信 JSAPI 与小程序共用后台产品开关，按身份字段选择汇付 trade_type。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function wxpayJsapiTradeType(array $order): string
    {
        $payment = (array) ($order['extra']['payment'] ?? []);

        return (string) ($payment['mini_openid'] ?? '') !== '' ? 'T_MINIAPP' : 'T_JSAPI';
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $authCode 付款码
     * @return array<string, mixed>
     */
    private function barcodePay(array $order, string $authCode): array
    {
        $this->ensureProduct(self::PRODUCT_BARCODE);

        try {
            $data = $this->client()->request('/v3/trade/payment/micropay', $this->baseOrder($order) + [
                'auth_code' => $authCode,
            ]);
        } catch (HuifuSdkException $e) {
            throw new PaymentException('汇付付款码下单失败：' . $e->getMessage(), 40200);
        }

        $success = (string) ($data['trans_stat'] ?? '') === 'S';

        return [
            'pay_page' => $success ? 'ok' : 'page',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => self::PRODUCT_BARCODE,
            'pay_action' => 'micropay',
            'pay_params' => [
                '_page' => 'page',
                'params' => $success ? '支付成功' : '等待用户确认支付',
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['hf_seq_id'] ?? ''),
        ];
    }

    /**
     * 请求斗拱聚合下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $tradeType 汇付交易类型
     * @param string $userId JSAPI 用户标识
     * @return array<string, mixed>
     */
    private function requestJspay(array $order, string $tradeType, string $userId = ''): array
    {
        $payload = $this->baseOrder($order) + [
            'trade_type' => $tradeType,
        ];

        if ($tradeType === 'A_JSAPI') {
            $payload['alipay_data'] = json_encode([
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'buyer_id' => $userId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($tradeType === 'A_NATIVE') {
            $payload['alipay_data'] = json_encode([
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (in_array($tradeType, ['T_JSAPI', 'T_MINIAPP'], true)) {
            $payload['wx_data'] = json_encode([
                'sub_openid' => $userId,
                'openid' => $userId,
                'device_info' => '4',
                'spbill_create_ip' => (string) $order['client_ip'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($tradeType === 'T_NATIVE') {
            $payload['wx_data'] = json_encode([
                'product_id' => '01001',
                'spbill_create_ip' => (string) $order['client_ip'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $data = $this->client()->request('/v3/trade/payment/jspay', $payload);
        } catch (HuifuSdkException $e) {
            throw new PaymentException('汇付下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['resp_code'] ?? '') !== '00000100') {
            throw new PaymentException('汇付下单失败：' . (string) ($data['resp_desc'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        return $data;
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
            'req_date' => substr((string) $order['pay_no'], 0, 8),
            'req_seq_id' => (string) $order['pay_no'],
            'huifu_id' => $this->huifuId(),
            'trans_amt' => FormatHelper::amount((int) $order['amount']),
            'goods_desc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
            'risk_check_data' => json_encode(['ip_addr' => (string) $order['client_ip']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 获取汇付客户端。
     */
    private function client(): HuifuClient
    {
        if ($this->client === null) {
            $this->client = new HuifuClient([
                'sys_id' => $this->configText('sys_id'),
                'product_id' => $this->configText('product_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'huifu_public_key' => $this->configText('huifu_public_key'),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 汇付交易主体号：渠道商模式使用子商户号，直连商户使用系统号。
     */
    private function huifuId(): string
    {
        $subMerchantNo = $this->configText('sub_merchant_no');

        return $subMerchantNo !== '' ? $subMerchantNo : $this->configText('sys_id');
    }

    /**
     * 将汇付交易状态映射为 MPAY 状态。
     */
    private function tradeStatus(string $status): string
    {
        return match ($status) {
            'S' => PaymentPluginStatusConstant::SUCCESS,
            'F', 'C' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前汇付通道未开启该支付产品', 40200, ['product' => $product]);
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
