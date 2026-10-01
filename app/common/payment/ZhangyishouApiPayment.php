<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\zhangyishou\ZhangyishouClient;
use app\common\sdk\zhangyishou\ZhangyishouSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use JsonException;
use support\Request;
use support\Response;

/**
 * 掌易收聚合支付插件。
 */
class ZhangyishouApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PROFILE_RAINBOW_LEGACY = 'rainbow_legacy';
    private const PRODUCT_DEFAULT_CHANNEL = 'default_channel';
    private const PRODUCT_WXPAY_MOBILE = 'wxpay_mobile';

    private ?ZhangyishouClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'zhangyishou_api',
        'name' => '掌易收聚合支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'qqpay', 'wxpay', 'bank'],
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
                'field' => 'merchant_id',
                'title' => '登录账号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '登录账号不能为空'],
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
                'type' => 'password',
                'field' => 'api_key',
                'title' => '商户密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pay_channel_id',
                'title' => '默认通道ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '默认通道ID不能为空'],
                ],
                'props' => [
                    'placeholder' => '填写单个 PayChannelId，不支持竖线拼接',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'wxpay_mobile_channel_id',
                'title' => '微信移动端通道ID',
                'value' => '',
                'props' => [
                    'placeholder' => '仅填写普通移动端微信专用 PayChannelId',
                ],
            ],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_DEFAULT_CHANNEL => '默认通道产品',
                self::PRODUCT_WXPAY_MOBILE => '微信普通移动端产品',
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
        $handlers = [
            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_DEFAULT_CHANNEL,
                    'qqpay' => self::PRODUCT_DEFAULT_CHANNEL,
                    'wxpay' => self::PRODUCT_DEFAULT_CHANNEL,
                    'bank' => self::PRODUCT_DEFAULT_CHANNEL,
                ],
                'handler' => fn (): array => $this->createDefaultPay($order),
            ],
        ];
        if ((string) ($order['pay_type_code'] ?? '') === 'wxpay'
            && in_array($this->paymentEnv($order), [
                EpayProtocolConstant::DEVICE_MOBILE,
                EpayProtocolConstant::DEVICE_QQ,
                EpayProtocolConstant::DEVICE_ALIPAY,
            ], true)
            && $this->configText('wxpay_mobile_channel_id') !== '') {
            $handlers['urlscheme'] = [
                'products' => [
                    'wxpay' => self::PRODUCT_WXPAY_MOBILE,
                ],
                'handler' => fn (): array => $this->createMobileWxpay($order),
            ];
        }

        return $this->executeDirectPaymentProduct($order, $handlers, '掌易收');
    }

    /**
     * 使用默认通道创建掌易收支付单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function createDefaultPay(array $order): array
    {
        return $this->createPay(
            $order,
            self::PRODUCT_DEFAULT_CHANNEL,
            $this->configuredChannelId('pay_channel_id', true)
        );
    }

    /**
     * 使用微信普通移动端通道创建掌易收支付单。
     *
     * 该通道只在非微信的移动客户端使用；是否属于 URL Scheme 由返回值本身决定。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function createMobileWxpay(array $order): array
    {
        $env = $this->paymentEnv($order);
        if (!in_array($env, [
            EpayProtocolConstant::DEVICE_MOBILE,
            EpayProtocolConstant::DEVICE_QQ,
            EpayProtocolConstant::DEVICE_ALIPAY,
        ], true)) {
            throw new PaymentProductUnavailableException('掌易收微信移动端产品不适用于当前环境', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
            ]);
        }

        $channelId = $this->configuredChannelId('wxpay_mobile_channel_id');
        if ($channelId === '') {
            throw new PaymentProductUnavailableException('掌易收微信普通移动端产品未配置', 40200, [
                'channel_error_code' => 'PRODUCT_NOT_OPEN',
            ]);
        }

        return $this->createPay($order, self::PRODUCT_WXPAY_MOBILE, $channelId);
    }

    /**
     * 创建掌易收支付单并按真实返回值生成标准承接参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function createPay(array $order, string $product, string $payChannelId): array
    {
        $payType = trim((string) ($order['pay_type_code'] ?? ''));
        $params = [
            'MerchantId' => $this->configTextRequired('merchant_id'),
            'DownstreamOrderNo' => $this->orderTextRequired($order, 'pay_no'),
            'OrderTime' => date('Y-m-d H:i:s'),
            'PayChannelId' => $payChannelId,
            'AsynPath' => $this->orderTextRequired($order, 'callback_url'),
            'OrderMoney' => FormatHelper::amount((int) ($order['amount'] ?? 0)),
            'IPPath' => $this->orderTextRequired($order, 'client_ip'),
            'Mproductdesc' => mb_strcut((string) ($order['subject'] ?? ''), 0, 127, 'UTF-8'),
        ];
        if ($this->shouldSendReturnUrl($payType, $this->paymentEnv($order))) {
            $params['ReturnUrl'] = $this->orderTextRequired($order, 'return_url');
        }

        try {
            $data = $this->client()->addOrder($params);
        } catch (ZhangyishouSdkException $e) {
            $this->throwSdkException($e, '下单');
        }

        $info = $data['Info'] ?? null;
        if (!is_string($info) || trim($info) === '') {
            throw new PaymentUncertainException('掌易收下单成功响应缺少字符串 Info', 40200);
        }
        $info = trim($info);
        [$payPage, $payParams] = $this->presentation($order, $product, $info);

        return $this->pendingPaymentResult($order, [
            'pay_page' => $payPage,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'Order.AddOrder',
            'channel_context' => [
                'protocol_profile' => self::PROFILE_RAINBOW_LEGACY,
                'pay_channel_id' => $payChannelId,
            ],
            'pay_params' => $payParams,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('掌易收插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('掌易收插件暂不支持关单', 40200);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $channelTradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($channelTradeNo === '') {
            throw new PaymentDefinitiveException('掌易收退款缺少支付成功回调的 OrderNo', 40200);
        }

        try {
            $data = $this->client()->refund([
                'MerchantId' => $this->configTextRequired('merchant_id'),
                'MerchantOrder' => $channelTradeNo,
                'RefundAmount' => FormatHelper::amount((int) ($order['refund_amount'] ?? 0)),
            ]);
        } catch (ZhangyishouSdkException $e) {
            $this->throwSdkException($e, '退款');
        }

        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) $order['refund_amount'],
            'chan_refund_no' => (string) ($data['RefundNo'] ?? ''),
            'channel_status' => (string) $data['Code'],
            'message' => '掌易收退款接口已受理，最终资金状态待确认',
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
        try {
            $payload = json_decode($request->rawBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PaymentException('掌易收回调不是合法 JSON', 40200);
        }
        if (!is_array($payload) || $payload === [] || array_is_list($payload)) {
            throw new PaymentException('掌易收回调必须是 JSON object', 40200);
        }
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('掌易收回调验签失败', 40200);
        }

        $merchantId = $this->notifyTextRequired($payload, 'MerchantId');
        if (!hash_equals($this->configTextRequired('merchant_id'), $merchantId)) {
            throw new PaymentException('掌易收回调 MerchantId 与通道配置不一致', 40200);
        }

        $payNo = $this->notifyTextRequired($payload, 'DownstreamOrderNo');
        $channelTradeNo = $this->notifyTextRequired($payload, 'OrderNo');
        $orderState = $this->notifyTextRequired($payload, 'OrderState');
        $paidAmount = $this->yuanToCents(
            $this->notifyTextRequired($payload, 'OrderMoney'),
            '掌易收回调金额'
        );
        $success = $orderState === '1';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => $payNo,
            'paid_amount' => $success ? $paidAmount : null,
            'message' => $this->notifyText($payload, 'Remark', $orderState),
            'chan_order_no' => $payNo,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => $orderState,
        ];
    }

    /**
     * 返回掌易收成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'OK';
    }

    /**
     * 返回掌易收失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'ERROR';
    }

    /**
     * 按支付环境和返回 URI 的真实类型生成承接参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array{0:string,1:array<string, string>}
     */
    private function presentation(array $order, string $product, string $info): array
    {
        if ($this->looksStructured($info)) {
            throw new PaymentUncertainException('掌易收下单 Info 返回了未定义的结构化内容', 40200);
        }

        $payType = (string) ($order['pay_type_code'] ?? '');
        $direct = $product === self::PRODUCT_WXPAY_MOBILE
            || $this->shouldDirectPresentation($payType, $this->paymentEnv($order));
        if (!$direct) {
            return ['qrcode', ['qrcode' => $info]];
        }
        if ($this->isHttpUrl($info)) {
            return ['jump', ['url' => $info]];
        }
        if ($product === self::PRODUCT_WXPAY_MOBILE && $this->isWechatScheme($info)) {
            return ['urlscheme', ['urlscheme' => $info]];
        }

        throw new PaymentUncertainException('掌易收下单 Info 无法确认是跳转 URL、URL Scheme 或 JSAPI 参数', 40200);
    }

    /**
     * 判断 rainbow_legacy 档案是否要求把同步返回地址发送给上游。
     */
    private function shouldSendReturnUrl(string $payType, string $env): bool
    {
        return ($payType === 'qqpay' && $env === EpayProtocolConstant::DEVICE_QQ)
            || ($payType === 'wxpay' && $env === EpayProtocolConstant::DEVICE_WECHAT);
    }

    /**
     * 判断 rainbow_legacy 档案是否在当前客户端直接打开上游返回值。
     */
    private function shouldDirectPresentation(string $payType, string $env): bool
    {
        return ($payType === 'alipay' && $env === EpayProtocolConstant::DEVICE_ALIPAY)
            || $this->shouldSendReturnUrl($payType, $env);
    }

    /**
     * 解析 MPAY 已归一化的支付环境。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function paymentEnv(array $order): string
    {
        $env = strtolower(trim((string) ($order['_env'] ?? EpayProtocolConstant::DEVICE_PC)));

        return in_array($env, EpayProtocolConstant::v1Devices(), true)
            ? $env
            : EpayProtocolConstant::DEVICE_PC;
    }

    /**
     * 读取独立通道 ID，拒绝竖线拼接配置进入运行时。
     */
    private function configuredChannelId(string $key, bool $required = false): string
    {
        $channelId = $this->configText($key);
        if (str_contains($channelId, '|')) {
            throw new PaymentDefinitiveException('掌易收通道 ID 必须使用独立配置字段，不支持竖线拼接', 40200);
        }
        if ($required && $channelId === '') {
            throw new PaymentDefinitiveException('掌易收默认通道 ID 不能为空', 40200);
        }

        return $channelId;
    }

    /**
     * 判断返回值是否像未定义的 JSON/JSAPI 结构。
     */
    private function looksStructured(string $value): bool
    {
        $first = $value[0] ?? '';

        return $first === '{' || $first === '[';
    }

    /**
     * 判断返回值是否为 HTTP(S) URL。
     */
    private function isHttpUrl(string $value): bool
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 判断微信移动通道是否返回微信 URL Scheme。
     */
    private function isWechatScheme(string $value): bool
    {
        return str_starts_with(strtolower($value), 'weixin:');
    }

    /**
     * 把 SDK 异常映射为支付运行时确定性语义。
     */
    private function throwSdkException(ZhangyishouSdkException $e, string $operation): never
    {
        $message = sprintf('掌易收%s失败：%s', $operation, $e->getMessage());
        if ($e->isUncertain()) {
            throw new PaymentUncertainException($message, 40200);
        }

        throw new PaymentDefinitiveException($message, 40200);
    }

    /**
     * 读取回调必填标量字段。
     *
     * @param array<string, mixed> $payload 回调对象
     */
    private function notifyTextRequired(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new PaymentException('掌易收回调缺少字段：' . $key, 40200);
        }

        return trim((string) $value);
    }

    /**
     * 读取回调可选标量字段。
     *
     * @param array<string, mixed> $payload 回调对象
     */
    private function notifyText(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : $default;
    }

    /**
     * 读取标准下单必填字段。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function orderTextRequired(array $order, string $key): string
    {
        $value = $order[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new PaymentDefinitiveException('掌易收下单缺少字段：' . $key, 40200);
        }

        return trim((string) $value);
    }

    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): ZhangyishouClient
    {
        if ($this->client === null) {
            $this->client = new ZhangyishouClient([
                'merchant_no' => $this->configText('merchant_no'),
                'api_key' => $this->configText('api_key'),
            ]);
        }

        return $this->client;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }

    /**
     * 读取必填插件配置。
     */
    private function configTextRequired(string $key): string
    {
        $value = $this->configText($key);
        if ($value === '') {
            throw new PaymentDefinitiveException('掌易收缺少插件配置：' . $key, 40200);
        }

        return $value;
    }
}
