<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\FileConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\lakala\LakalaOpenApiClient;
use app\common\sdk\lakala\LakalaSdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 拉卡拉 OpenAPI 支付插件。
 *
 * 迁移自彩虹易支付 `lakala` 插件，并按 MPAY V2 插件契约重写：
 * 插件只负责调用拉卡拉接口和返回标准结构，订单状态、回调日志和商户通知由平台服务层处理。
 */
class LakalaApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_CASHIER = 'cashier';

    private ?LakalaOpenApiClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'lakala_api',
        'name' => '拉卡拉OpenAPI支付',
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
                'title' => 'APPID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'APPID不能为空'],
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
                'type' => 'input',
                'field' => 'terminal_no',
                'title' => '终端号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '终端号不能为空'],
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
                    ['label' => '聚合收银台', 'value' => self::PRODUCT_CASHIER],
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
                    'placeholder' => '留空使用拉卡拉默认网关',
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'platform_cert_path',
                'title' => '拉卡拉平台证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [
                    ['required' => true, 'message' => '拉卡拉平台证书不能为空'],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_cert_path',
                'title' => '商户证书',
                'value' => '',
                'props' => $this->uploadProps('.cer,.crt,.pem'),
                'validate' => [
                    ['required' => true, 'message' => '商户证书不能为空'],
                ],
            ],
            [
                'type' => 'upload',
                'field' => 'merchant_private_key_path',
                'title' => '商户私钥文件',
                'value' => '',
                'props' => $this->uploadProps('.pem,.key'),
                'validate' => [
                    ['required' => true, 'message' => '商户私钥文件不能为空'],
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

        if ($this->productEnabled(self::PRODUCT_CASHIER)) {
            return $this->cashierPay($order, $payType);
        }

        if ($method === 'scan') {
            return $this->micropay($order, $payType);
        }

        if ($payType === 'alipay' && $method === 'jsapi') {
            return $this->preorder($order, self::PRODUCT_ALIPAY_JSAPI, 'ALIPAY', '51');
        }
        if ($payType === 'wxpay' && $method === 'jsapi') {
            return $this->preorder($order, self::PRODUCT_WXPAY_JSAPI, 'WECHAT', '51');
        }
        if ($payType === 'bank') {
            return $this->preorder($order, self::PRODUCT_BANK_SCAN, 'UQRCODEPAY', '41');
        }
        if ($payType === 'wxpay') {
            return $this->preorder($order, self::PRODUCT_WXPAY_SCAN, 'WECHAT', '41');
        }

        return $this->preorder($order, self::PRODUCT_ALIPAY_SCAN, 'ALIPAY', '41');
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
            $data = $this->client()->execute('/api/v3/labs/query/tradequery', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => (string) $order['pay_no'],
            ]);
        } catch (LakalaSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = $this->tradeStatus((string) ($data['trade_state'] ?? ''));

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['trade_no'] ?? $order['chan_trade_no'] ?? $order['pay_no']),
            'channel_status' => (string) ($data['trade_state'] ?? ''),
            'message' => (string) ($data['trade_state_desc'] ?? $data['trade_state'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['pay_time'] ?? null) : null,
            'raw_data' => $data,
        ];
    }

    /**
     * 撤销支付订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        try {
            $data = $this->client()->execute('/api/v3/labs/relation/revoked', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => 'CLOSE' . date('YmdHis') . random_int(1000, 9999),
                'origin_out_trade_no' => (string) $order['pay_no'],
                'location_info' => [
                    'request_ip' => (string) ($order['client_ip'] ?? ''),
                ],
            ]);

            return [
                'success' => true,
                'msg' => '关单成功',
                'raw_data' => $data,
            ];
        } catch (LakalaSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }
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
            $data = $this->client()->execute('/api/v3/labs/relation/refund', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => (string) $order['refund_no'],
                'refund_amount' => (string) (int) $order['refund_amount'],
                'origin_out_trade_no' => (string) $order['pay_no'],
                'origin_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'location_info' => [
                    'request_ip' => (string) ($order['client_ip'] ?? ''),
                ],
            ]);

            return [
                'success' => true,
                'msg' => '退款申请成功',
                'chan_refund_no' => (string) ($data['trade_no'] ?? $order['refund_no']),
                'raw_data' => $data,
            ];
        } catch (LakalaSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * 解析拉卡拉异步通知。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $body = $request->rawBody();
        $authorization = (string) $request->header('authorization', '');
        if ($body === '' || $authorization === '') {
            throw new PaymentException('拉卡拉回调缺少原始报文或签名头', 40200);
        }
        if (!$this->client()->verifyNotify($authorization, $body)) {
            throw new PaymentException('拉卡拉回调验签失败', 40200);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new PaymentException('拉卡拉回调报文不是合法 JSON', 40200);
        }

        $outTradeNo = (string) ($data['out_trade_no'] ?? $data['out_order_no'] ?? '');
        $tradeInfo = (array) ($data['order_trade_info'] ?? []);
        $channelTradeNo = (string) ($data['trade_no'] ?? $tradeInfo['trade_no'] ?? $outTradeNo);
        $channelStatus = (string) ($data['trade_status'] ?? $data['order_status'] ?? '');
        $status = $this->notifyStatus($channelStatus);

        if ($outTradeNo === '') {
            throw new PaymentException('拉卡拉回调缺少商户订单号', 40200);
        }

        return [
            'status' => $status,
            'message' => $channelStatus,
            'channel_order_no' => $outTradeNo,
            'channel_trade_no' => $channelTradeNo !== '' ? $channelTradeNo : $outTradeNo,
            'channel_status' => $channelStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($data['pay_time'] ?? null) : null,
        ];
    }

    /**
     * 返回拉卡拉成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回拉卡拉失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 聚合预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $accountType 拉卡拉账户类型
     * @param string $transType 拉卡拉交易类型
     * @return array<string, mixed>
     */
    private function preorder(array $order, string $product, string $accountType, string $transType): array
    {
        $this->ensureProduct($product);

        $payload = [
            'merchant_no' => $this->configText('merchant_no'),
            'term_no' => $this->configText('terminal_no'),
            'out_trade_no' => (string) $order['pay_no'],
            'account_type' => $accountType,
            'trans_type' => $transType,
            'total_amount' => (string) (int) $order['amount'],
            'location_info' => [
                'request_ip' => (string) $order['client_ip'],
            ],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
        ];

        $extend = $this->identityFields($order);
        if ($extend !== []) {
            $payload['acc_busi_fields'] = $extend;
        }

        try {
            $data = $this->client()->execute('/api/v3/labs/trans/preorder', $payload);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉下单失败：' . $e->getMessage(), 40200);
        }

        $fields = (array) ($data['acc_resp_fields'] ?? []);
        $payPage = str_ends_with($product, '_jsapi') ? 'jsapi' : 'qrcode';
        $payParams = $payPage === 'jsapi'
            ? array_replace($fields, ['raw' => $data])
            : [
                'qrcode' => (string) ($fields['code'] ?? $fields['redirect_url'] ?? ''),
                'raw' => $data,
            ];

        if ($payPage === 'qrcode' && $payParams['qrcode'] === '') {
            throw new PaymentException('拉卡拉未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => $payPage,
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'preorder',
            'pay_params' => $payParams,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 聚合收银台下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function cashierPay(array $order, string $payType): array
    {
        $payMode = match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNION',
            default => 'ALIPAY',
        };

        try {
            $data = $this->client()->cashier('/api/v3/ccss/counter/order/special_create', [
                'out_order_no' => (string) $order['pay_no'],
                'merchant_no' => $this->configText('merchant_no'),
                'total_amount' => (string) (int) $order['amount'],
                'order_efficient_time' => date('YmdHis', time() + 1200),
                'notify_url' => (string) $order['callback_url'],
                'support_refund' => 1,
                'callback_url' => (string) $order['return_url'],
                'order_info' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'counter_param' => json_encode(['pay_mode' => $payMode], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉收银台下单失败：' . $e->getMessage(), 40200);
        }

        $url = (string) ($data['counter_url'] ?? '');
        if ($url === '') {
            throw new PaymentException('拉卡拉收银台未返回支付地址', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => $payType,
            'pay_product' => self::PRODUCT_CASHIER,
            'pay_action' => 'cashierPay',
            'pay_params' => [
                'url' => $url,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['pay_order_no'] ?? ''),
        ];
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function micropay(array $order, string $payType): array
    {
        $authCode = (string) ($order['extra']['payment']['auth_code'] ?? '');
        if ($authCode === '') {
            throw new PaymentException('付款码不能为空', 40200);
        }

        try {
            $data = $this->client()->execute('/api/v3/labs/trans/micropay', [
                'merchant_no' => $this->configText('merchant_no'),
                'term_no' => $this->configText('terminal_no'),
                'out_trade_no' => (string) $order['pay_no'],
                'auth_code' => $authCode,
                'total_amount' => (string) (int) $order['amount'],
                'location_info' => [
                    'request_ip' => (string) $order['client_ip'],
                ],
                'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'notify_url' => (string) $order['callback_url'],
            ]);
        } catch (LakalaSdkException $e) {
            throw new PaymentException('拉卡拉付款码支付失败：' . $e->getMessage(), 40200);
        }

        $status = strtoupper((string) ($data['trade_state'] ?? ''));

        return [
            'pay_page' => $status === 'SUCCESS' ? 'ok' : 'page',
            'pay_type' => $payType,
            'pay_product' => 'micropay',
            'pay_action' => 'micropay',
            'pay_params' => [
                '_page' => 'ok',
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 构造 JSAPI 身份参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function identityFields(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            return [];
        }

        $fields = ['user_id' => $userId];
        $subAppId = (string) ($payment['sub_appid'] ?? '');
        if ($subAppId !== '') {
            $fields['sub_appid'] = $subAppId;
        }

        return $fields;
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): LakalaOpenApiClient
    {
        if ($this->client === null) {
            $this->client = new LakalaOpenApiClient([
                'app_id' => $this->configText('app_id'),
                'merchant_no' => $this->configText('merchant_no'),
                'terminal_no' => $this->configText('terminal_no'),
                'platform_cert_path' => $this->uploadedPrivateFilePath($this->configText('platform_cert_path')),
                'merchant_cert_path' => $this->uploadedPrivateFilePath($this->configText('merchant_cert_path')),
                'merchant_private_key_path' => $this->uploadedPrivateFilePath($this->configText('merchant_private_key_path')),
                'sandbox' => $this->configBool('sandbox'),
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
     * 判断产品是否启用。
     */
    private function productEnabled(string $product): bool
    {
        return in_array($product, $this->enabledProducts(), true);
    }

    /**
     * 校验产品是否启用。
     */
    private function ensureProduct(string $product): void
    {
        if (!$this->productEnabled($product)) {
            throw new PaymentException('当前拉卡拉通道未开启该支付产品', 40200, ['product' => $product]);
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
        return match (strtoupper($status)) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED', 'REVOKED', 'FAIL', 'FAILED' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
    }

    /**
     * 映射通知状态。
     */
    private function notifyStatus(string $status): string
    {
        $status = strtoupper($status);
        if ($status === 'SUCCESS' || $status === '2') {
            return PaymentPluginStatusConstant::SUCCESS;
        }

        return in_array($status, ['CLOSED', 'REVOKED', 'FAIL', 'FAILED', '3'], true)
            ? PaymentPluginStatusConstant::FAILED
            : PaymentPluginStatusConstant::PENDING;
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
