<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\AuthConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 支付插件模板示例。
 *
 * 复制这个类时，通常只需要改下面几处：
 * - `paymentInfo` 里的 `code`、`name`、`pay_types`、`config_schema`
 * - `init()` 里的 SDK 初始化和配置装配
 * - `pay()` 里的真实第三方下单逻辑
 * - `query()`、`close()`、`refund()`、`notify()` 里的真实接口调用和验签逻辑
 *
 * 这是一个安全的起点模板，不依赖任何第三方 SDK。
 */
class TemplatePayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    /**
     * 插件元信息。
     *
     * 复制后请优先修改 `code` 和 `pay_types`，避免和真实插件混淆。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'template',
        'name' => '模板示例插件',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['template'],
        'transfer_types' => [],
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'gateway_url',
                'title' => '网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入第三方网关地址',
                ],
                'validate' => [
                    [
                        'required' => true,
                        'message' => '网关地址不能为空',
                    ],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户号',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入商户号',
                ],
                'validate' => [
                    [
                        'required' => true,
                        'message' => '商户号不能为空',
                    ],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '应用ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入应用ID',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'app_secret',
                'title' => '签名密钥/私钥',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入签名密钥或私钥内容',
                    'rows' => 4,
                ],
                'validate' => [
                    [
                        'required' => true,
                        'message' => '签名密钥不能为空',
                    ],
                ],
            ],
            [
                'type' => 'select',
                'field' => 'sign_type',
                'title' => '签名类型',
                'value' => AuthConstant::API_SIGN_NAME_MD5,
                'props' => [
                    'placeholder' => '请选择签名类型',
                ],
                'options' => [
                    [
                        'value' => AuthConstant::API_SIGN_NAME_MD5,
                        'label' => AuthConstant::API_SIGN_NAME_MD5,
                    ],
                    [
                        'value' => 'RSA2',
                        'label' => 'RSA2',
                    ],
                ],
            ],
            [
                'type' => 'select',
                'field' => 'default_product',
                'title' => '默认支付形态',
                'value' => 'html',
                'props' => [
                    'placeholder' => '请选择默认支付形态',
                ],
                'options' => [
                    [
                        'value' => 'html',
                        'label' => '表单跳转',
                    ],
                    [
                        'value' => 'qrcode',
                        'label' => '二维码',
                    ],
                    [
                        'value' => 'jump',
                        'label' => '链接跳转',
                    ],
                    [
                        'value' => 'jsapi',
                        'label' => 'JSAPI / 拉起参数',
                    ],
                ],
            ],
        ],
    ];

    /**
     * 初始化插件。
     *
     * 模板插件这里只做基础注入；真实插件可以在这里初始化 SDK、缓存配置或预处理证书。
     *
     * @param array $channelConfig 渠道配置
     * @return void
     */
    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
    }

    /**
     * 发起支付下单。
     *
     * 这里保留的是“模板返回结构”，便于复制后直接替换成真实第三方调用。
     *
     * @param array $order 订单参数
     * @return array{
     *     pay_product: string,
     *     pay_action: string,
     *     pay_params: array<string, mixed>,
     *     chan_order_no: string,
     *     chan_trade_no: string
     * }
     * @throws PaymentException
     */
    public function pay(array $order): array
    {
        $orderNo = $this->requireOrderNo($order);
        $amount = $this->requireAmount($order);
        $subject = $this->requireSubject($order);
        $product = $this->resolveProduct($order);
        $payload = $this->buildRequestPayload($order, $orderNo, $amount, $subject);

        return [
            'pay_product' => $product,
            'pay_action' => $product,
            'pay_params' => $this->buildPayParams($product, $payload),
            'chan_order_no' => $orderNo,
            'chan_trade_no' => '',
        ];
    }

    /**
     * 查询订单状态。
     *
     * 复制后请在这里替换成真实查单接口。
     *
     * @param array $order 订单参数
     * @return array
     * @throws PaymentException
     */
    public function query(array $order): array
    {
        $this->throwTemplateTodo('查单');

        return [];
    }

    /**
     * 关闭订单。
     *
     * 复制后请在这里替换成真实关单接口。
     *
     * @param array $order 订单参数
     * @return array
     * @throws PaymentException
     */
    public function close(array $order): array
    {
        $this->throwTemplateTodo('关单');

        return [];
    }

    /**
     * 申请退款。
     *
     * 复制后请在这里替换成真实退款接口。
     *
     * @param array $order 订单参数
     * @return array
     * @throws PaymentException
     */
    public function refund(array $order): array
    {
        $this->throwTemplateTodo('退款');

        return [];
    }

    /**
     * 解析并验证支付回调通知。
     *
     * 复制后请在这里替换成真实回调验签和结果解析逻辑。
     * 验签失败直接抛出 `PaymentException`，验签通过后返回标准结果数组。
     * 如果渠道只返回一个唯一订单号，请同时填充 `channel_order_no` 和 `channel_trade_no`。
     *
     * @param Request $request 请求对象
     * @return array
     * @throws PaymentException
     */
    public function notify(Request $request): array
    {
        $this->throwTemplateTodo('回调验签');

        return [];
    }

    /**
     * 回调成功响应。
     *
     * @return string|Response
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 回调失败响应。
     *
     * @return string|Response
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 构造第三方请求参数。
     *
     * 这里的字段只是示例，复制后按真实第三方接口自行增删。
     *
     * @param array $order 原始订单参数
     * @param string $orderNo 商户订单号
     * @param int $amount 金额（分）
     * @param string $subject 订单标题
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $order, string $orderNo, int $amount, string $subject): array
    {
        $payload = [
            'merchant_no' => (string) $this->getConfig('merchant_no', ''),
            'app_id' => (string) $this->getConfig('app_id', ''),
            'pay_no' => (string) ($order['pay_no'] ?? $orderNo),
            'out_trade_no' => $orderNo,
            'biz_no' => (string) ($order['biz_no'] ?? ''),
            'trace_no' => (string) ($order['trace_no'] ?? ''),
            'channel_request_no' => (string) ($order['channel_request_no'] ?? ''),
            'amount' => $amount,
            'amount_yuan' => FormatHelper::amount($amount),
            'subject' => $subject,
            'body' => (string) ($order['body'] ?? ''),
            'notify_url' => (string) ($order['callback_url'] ?? ''),
            'return_url' => (string) ($order['return_url'] ?? ''),
            'device' => (string) ($order['_env'] ?? 'pc'),
            'extra' => $this->collectOrderContext($order),
        ];

        $signType = strtoupper((string) $this->getConfig('sign_type', AuthConstant::API_SIGN_NAME_MD5));
        $payload['sign_type'] = $signType !== '' ? $signType : AuthConstant::API_SIGN_NAME_MD5;
        $payload['sign'] = 'TODO';

        return $payload;
    }

    /**
     * 生成支付页返回参数。
     *
     * @param string $product 支付形态
     * @param array<string, mixed> $payload 请求参数
     * @return array<string, mixed>
     */
    private function buildPayParams(string $product, array $payload): array
    {
        $gatewayUrl = (string) $this->getConfig('gateway_url', '');

        return match ($product) {
            'qrcode' => [
                'type' => 'qrcode',
                'qrcode_text' => '请替换为真实二维码内容',
                'qrcode_url' => $gatewayUrl,
                'payload' => $payload,
            ],
            'jump' => [
                'type' => 'jump',
                'redirect_url' => $gatewayUrl,
                'payload' => $payload,
            ],
            'jsapi' => [
                'type' => 'jsapi',
                'order_string' => '请替换为真实调起参数',
                'payload' => $payload,
            ],
            default => [
                'type' => 'html',
                'method' => 'POST',
                'action' => $gatewayUrl,
                'html' => $this->buildAutoSubmitForm($gatewayUrl, $payload),
                'payload' => $payload,
            ],
        };
    }

    /**
     * 生成自动提交表单。
     *
     * 这是很多表单跳转类插件最常见的返回方式，复制后可以直接改成真实字段。
     *
     * @param string $action 表单地址
     * @param array<string, mixed> $fields 表单字段
     * @return string HTML 片段
     */
    private function buildAutoSubmitForm(string $action, array $fields): string
    {
        if ($action === '') {
            return '<!-- 请在模板插件中替换为真实表单地址 -->';
        }

        $inputs = '';
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $value = $encoded !== false ? $encoded : '';
            }

            $key = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
            $value = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $inputs .= sprintf('<input type="hidden" name="%s" value="%s">', $key, $value);
        }

        $action = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<form id="template-pay-form" action="%s" method="post">%s</form><script>document.getElementById("template-pay-form").submit();</script>',
            $action,
            $inputs
        );
    }

    /**
     * 归一化订单上下文。
     *
     * 支付单拉起时，`extra` 使用 merchant/payment/presentation/plugin 分区。
     * 模板把常用分区展开到同一层，方便新插件读取 `param`、`method`、`auth_code` 等字段。
     *
     * @param array $order 原始订单参数
     * @return array<string, mixed>
     */
    private function collectOrderContext(array $order): array
    {
        $context = $order;
        $extra = $this->normalizeBag($order['extra'] ?? null);
        $context = array_merge($context, $extra);
        foreach (['merchant', 'payment', 'source'] as $section) {
            if (isset($extra[$section]) && is_array($extra[$section])) {
                $context = array_merge($context, $extra[$section]);
            }
        }
        $context = array_merge($context, $this->normalizeBag($order['param'] ?? null));

        return $context;
    }

    /**
     * 标准化数组、JSON 字符串或查询字符串。
     *
     * @param mixed $value 原始值
     * @return array<string, mixed>
     */
    private function normalizeBag(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            parse_str($value, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * 解析默认支付形态。
     *
     * @param array $order 原始订单参数
     * @return string
     */
    private function resolveProduct(array $order): string
    {
        $context = $this->collectOrderContext($order);
        $candidates = [
            $context['pay_product'] ?? null,
            $context['product'] ?? null,
            $context['pay_action'] ?? null,
            $context['action'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $product = $this->normalizeProductCode((string) $candidate);
            if ($product !== '') {
                return $product;
            }
        }

        return $this->normalizeProductCode((string) $this->getConfig('default_product', 'html')) ?: 'html';
    }

    /**
     * 规范化支付形态标识。
     *
     * @param string $product 原始标识
     * @return string 标准化后的标识
     */
    private function normalizeProductCode(string $product): string
    {
        $product = strtolower(trim($product));
        return in_array($product, ['html', 'qrcode', 'jump', 'jsapi'], true) ? $product : '';
    }

    /**
     * 获取并校验订单号。
     *
     * @param array $order 原始订单参数
     * @return string
     * @throws PaymentException
     */
    private function requireOrderNo(array $order): string
    {
        $orderNo = trim((string) ($order['order_id'] ?? $order['pay_no'] ?? $order['out_trade_no'] ?? ''));
        if ($orderNo === '') {
            throw new PaymentException('模板插件下单缺少订单号', 40200);
        }

        return $orderNo;
    }

    /**
     * 获取并校验金额。
     *
     * @param array $order 原始订单参数
     * @return int
     * @throws PaymentException
     */
    private function requireAmount(array $order): int
    {
        $amount = (int) ($order['amount'] ?? $order['pay_amount'] ?? $order['total_amount'] ?? 0);
        if ($amount <= 0) {
            throw new PaymentException('模板插件下单金额不合法', 40200);
        }

        return $amount;
    }

    /**
     * 获取并校验订单标题。
     *
     * @param array $order 原始订单参数
     * @return string
     * @throws PaymentException
     */
    private function requireSubject(array $order): string
    {
        $subject = trim((string) ($order['subject'] ?? $order['title'] ?? $order['body'] ?? ''));
        if ($subject === '') {
            throw new PaymentException('模板插件下单缺少标题', 40200);
        }

        return $subject;
    }

    /**
     * 抛出模板占位异常。
     *
     * @param string $action 当前动作
     * @return void
     * @throws PaymentException
     */
    private function throwTemplateTodo(string $action): void
    {
        throw new PaymentException(sprintf('模板插件示例未实现%s逻辑，请复制后接入真实网关', $action), 40200);
    }
}
