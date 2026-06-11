<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\sandpay\SandpayClient;
use app\common\sdk\sandpay\SandpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 杉德支付 API 插件。
 *
 * 迁移彩虹 `sandpay` 的统一下单、JSAPI、回调、查单和退款主链路。
 */
class SandpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';

    private ?SandpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'sandpay_api',
        'name' => '杉德支付API',
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
                'field' => 'merchant_no',
                'title' => '商户编号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户编号不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'private_cert_password',
                'title' => '私钥证书密码',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '私钥证书密码不能为空'],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'public_cert_path',
                'title' => '杉德公钥证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [
                    ['required' => true, 'message' => '杉德公钥证书不能为空'],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'private_cert_path',
                'title' => '商户私钥证书',
                'value' => '',
                'props' => $this->uploadProps('.pfx,.p12'),
                'validate' => [
                    ['required' => true, 'message' => '商户私钥证书不能为空'],
                ],
            ],
            [
                'type' => 'select',
                'field' => 'market_product',
                'title' => '市场产品',
                'value' => 'QZF',
                'options' => [
                    ['label' => '标准线上收款', 'value' => 'QZF'],
                    ['label' => '企业杉德宝', 'value' => 'CSDB'],
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
                    'placeholder' => '留空使用杉德默认网关',
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
                        'wxpay' => $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, 'WXPAY'),
                    };
                },
            ],
            'qrcode' => [
                'products' => [
                    'bank' => self::PRODUCT_BANK_SCAN,
                    'wxpay' => self::PRODUCT_WXPAY_SCAN,
                    'alipay' => self::PRODUCT_ALIPAY_SCAN,
                ],

                'handler' => fn (): array => $this->qrcodePayByType($order, $payType),
            ],
        ], '杉德');
    }

    /**
     * 按支付方式选择杉德扫码产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function qrcodePayByType(array $order, string $payType): array
    {
        return match ($payType) {
            'bank' => $this->qrcodePay($order, self::PRODUCT_BANK_SCAN, 'CUPPAY'),
            'wxpay' => $this->qrcodePay($order, self::PRODUCT_WXPAY_SCAN, 'WXPAY'),
            default => $this->qrcodePay($order, self::PRODUCT_ALIPAY_SCAN, 'ALIPAY'),
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
            $data = $this->client()->execute('/v4/sd-receipts/api/trans/trans.order.query', [
                'outReqTime' => date('YmdHis'),
                'mid' => $this->configText('merchant_no'),
                'outOrderNo' => (string) $order['pay_no'],
            ]);
        } catch (SandpaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = $this->tradeStatus((string) ($data['orderStatus'] ?? ''));

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['outOrderNo'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['sandSerialNo'] ?? $order['chan_trade_no'] ?? ''),
            'channel_status' => (string) ($data['orderStatus'] ?? ''),
            'message' => (string) ($data['orderStatus'] ?? ''),
            'raw_data' => $data,
        ];
    }

    /**
     * 杉德旧插件未提供支付关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '杉德插件暂不支持关单',
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
            $data = $this->client()->execute('/v4/sd-receipts/api/trans/trans.order.refund', [
                'marketProduct' => $this->configText('market_product') ?: 'QZF',
                'outReqTime' => date('YmdHis'),
                'mid' => $this->configText('merchant_no'),
                'outOrderNo' => (string) $order['refund_no'],
                'oriOutOrderNo' => (string) $order['pay_no'],
                'amount' => FormatHelper::amount((int) $order['refund_amount']),
                'notifyUrl' => '',
            ]);
        } catch (SandpaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['sandSerialNo'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['amount'] ?? 0)) * 100),
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
        $bizData = (string) ($request->post('bizData') ?? '');
        $sign = (string) ($request->post('sign') ?? '');
        if (!$this->client()->verify($bizData, $sign)) {
            throw new PaymentException('杉德回调验签失败', 40200);
        }

        $payload = json_decode($bizData, true);
        if (!is_array($payload)) {
            throw new PaymentException('杉德回调 bizData 不是合法 JSON', 40200);
        }
        $success = (string) ($payload['orderStatus'] ?? '') === 'success';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['orderStatus'] ?? ''),
            'channel_order_no' => (string) ($payload['outOrderNo'] ?? ''),
            'channel_trade_no' => (string) ($payload['sandSerialNo'] ?? $payload['channelOrderNo'] ?? ''),
            'channel_status' => (string) ($payload['orderStatus'] ?? ''),
        ];
    }

    /**
     * 返回杉德成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'respCode=000000';
    }

    /**
     * 返回杉德失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'respCode=020002';
    }

    /**
     * 二维码下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 杉德支付类型
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order, string $product, string $payType): array
    {
        $data = $this->requestCreateOrder($order, $product, $payType, 'QR');
        $qrcode = (string) (($data['credential'] ?? [])['qrCode'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('杉德扫码下单未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'trans.order.create',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['sandSerialNo'] ?? ''),
        ];
    }

    /**
     * JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 杉德支付类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['mini_openid'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('杉德JSAPI支付缺少用户标识', 40200);
        }

        $data = $this->requestCreateOrder($order, $product, $payType, 'JSAPI', $userId, (string) ($payment['sub_appid'] ?? ''));
        $credential = (array) ($data['credential'] ?? []);
        $params = $payType === 'ALIPAY'
            ? ['tradeNO' => (string) ($credential['tradeNo'] ?? '')]
            : $credential;
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'trans.order.create',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['sandSerialNo'] ?? ''),
        ];
    }

    /**
     * 请求杉德统一下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 支付类型
     * @param string $payMode 支付模式
     * @param string $userId 用户标识
     * @param string $subAppId 子应用 ID
     * @return array<string, mixed>
     */
    private function requestCreateOrder(array $order, string $product, string $payType, string $payMode, string $userId = '', string $subAppId = ''): array
    {
        $this->ensureProduct($product);

        $payload = [
            'marketProduct' => $this->configText('market_product') ?: 'QZF',
            'outReqTime' => date('YmdHis'),
            'mid' => $this->configText('merchant_no'),
            'outOrderNo' => (string) $order['pay_no'],
            'description' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'goodsClass' => '01',
            'amount' => FormatHelper::amount((int) $order['amount']),
            'payType' => $payType,
            'payMode' => $payMode,
            'payerInfo' => [
                'payAccLimit' => '',
            ],
            'notifyUrl' => (string) $order['callback_url'],
            'riskmgtInfo' => [
                'sourceIp' => (string) $order['client_ip'],
            ],
        ];
        if ($userId !== '' && $subAppId !== '') {
            $payload['payerInfo'] = [
                'subAppId' => $subAppId,
                'subUserId' => $userId,
                'frontUrl' => (string) $order['return_url'],
            ];
        } elseif ($userId !== '') {
            $payload['payerInfo'] = [
                'userId' => $userId,
                'frontUrl' => (string) $order['return_url'],
            ];
        }

        try {
            return $this->client()->execute('/v4/sd-receipts/api/trans/trans.order.create', $payload);
        } catch (SandpaySdkException $e) {
            throw new PaymentException('杉德下单失败：' . $e->getMessage(), 40200);
        }
    }

    /**
     * 获取杉德客户端。
     */
    private function client(): SandpayClient
    {
        if ($this->client === null) {
            $this->client = new SandpayClient([
                'merchant_no' => $this->configText('merchant_no'),
                'private_cert_password' => $this->configText('private_cert_password'),
                'public_cert_path' => $this->uploadedPrivateFilePath($this->configText('public_cert_path')),
                'private_cert_path' => $this->uploadedPrivateFilePath($this->configText('private_cert_path')),
                'sandbox' => (bool) $this->getConfig('sandbox', false),
                'api_base_url' => $this->configText('api_base_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 将上传组件保存的 object_key 转为可读本机路径。
     */
    private function uploadedPrivateFilePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        return runtime_path(trim($path, '/'));
    }

    /**
     * 构造上传字段配置。
     *
     * @param string $accept 允许文件后缀
     * @return array<string, mixed>
     */
    private function uploadProps(string $accept): array
    {
        return [
            'fileUpload' => [
                'scene' => FileConstant::SCENE_CERTIFICATE,
                'visibility' => FileConstant::VISIBILITY_PRIVATE,
                'storageEngine' => FileConstant::STORAGE_LOCAL,
                'getKey' => 'object_key',
                'accept' => $accept,
                'limit' => 1,
                'multiple' => false,
                'showFileList' => true,
            ],
        ];
    }

    /**
     * 将杉德交易状态映射为 MPAY 状态。
     */
    private function tradeStatus(string $status): string
    {
        return match ($status) {
            'success' => PaymentPluginStatusConstant::SUCCESS,
            'fail' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前杉德通道未开启该支付产品', 40200, ['product' => $product]);
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
