<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\hlpay\HlpayClient;
use app\common\sdk\hlpay\HlpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 汇联支付 API 插件。
 *
 * 提供支付宝/微信公众号 JSAPI、三种方式扫码、支付通知与退款能力，按产品声明
 * 用户身份并映射汇联 payType/paySubType；不声明未核验的银联 JSAPI。
 * 当前适配协议没有可确认的主动查单和关单接口。
 */
class HlpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_ALIPAY_JSAPI = 'ALIPAY_JSAPI';
    private const PRODUCT_WECHAT_JSAPI = 'WECHAT_JSAPI';
    private const PRODUCT_ALIPAY_NATIVE = 'ALIPAY_NATIVE';
    private const PRODUCT_WECHAT_NATIVE = 'WECHAT_NATIVE';
    private const PRODUCT_UNION_PAY_NATIVE = 'UNION_PAY_NATIVE';

    private ?HlpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'hlpay_api',
        'name' => '汇联支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.huilianlink.com/',
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
            ['type' => 'input', 'field' => 'app_id', 'title' => '应用APPID', 'value' => '', 'validate' => [['required' => true, 'message' => '应用APPID不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'input', 'field' => 'sub_sn', 'title' => '子商户编码', 'value' => ''],
            ['type' => 'input', 'field' => 'channel_code', 'title' => '通道编码', 'value' => ''],
            ['type' => 'select', 'field' => 'scene_type', 'title' => '场景类型', 'value' => '1', 'options' => [['label' => '线下', 'value' => '1'], ['label' => '线上', 'value' => '2']]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WECHAT_JSAPI => '微信 JSAPI',
                self::PRODUCT_ALIPAY_NATIVE => '支付宝扫码',
                self::PRODUCT_WECHAT_NATIVE => '微信扫码',
                self::PRODUCT_UNION_PAY_NATIVE => '银联扫码',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'alipay_oauth']),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '汇联支付');
    }

    /**
     * 为已选微信/支付宝 JSAPI 产品声明身份；扫码由上游支付页面处理。
     *
     * 旧插件银联只实现 NATIVE；当前不声明未核验的银联 JSAPI 或 MINI_APP。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_WECHAT_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_ALIPAY_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
            default => null,
        };
    }

    /**
     * 声明插件支持的产品，由身份预检和真实下单共同消费。
     *
     * @param array<string, mixed> $order 处理器消费的标准下单参数
     * @return array<string, array<string, mixed>> 产品与请求处理器，不执行上游请求
     */
    private function paymentProducts(array $order): array
    {
        return [
            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WECHAT_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_NATIVE,
                    'wxpay' => self::PRODUCT_WECHAT_NATIVE,
                    'bank' => self::PRODUCT_UNION_PAY_NATIVE,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],
        ];
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function scanPay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $product = match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNION_PAY',
            default => 'ALIPAY',
        };

        try {
            $data = $this->client()->execute('/openapi/pay/create', $this->basePayload($order) + [
                'payType' => $product,
                'paySubType' => 'NATIVE',
            ]);
        } catch (HlpaySdkException $e) {
            throw new PaymentException('汇联支付下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['payInfo'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('汇联支付未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $product . '_NATIVE', 'pay/create', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('汇联支付插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('汇联支付插件暂不支持关单', 40200);
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        try {
            $data = $this->client()->execute('/openapi/pay/refund', [
                'payOrderNo' => (string) ($order['chan_trade_no'] ?? ''),
                'mchRefundOrderNo' => (string) $order['refund_no'],
                'amount' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (HlpaySdkException $e) {
            throw new PaymentUncertainException('汇联支付退款结果不确定：' . $e->getMessage(), 40200);
        }

        $refundAmount = isset($data['refundAmount'])
            ? $this->yuanToCents($data['refundAmount'], '汇联支付退款金额')
            : (int) $order['refund_amount'];

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => $refundAmount,
            'chan_refund_no' => (string) ($data['instOrderNo'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析并验签支付回调。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('汇联支付回调验签失败', 40200);
        }

        $data = (array) ($payload['data'] ?? []);
        $success = (string) ($data['state'] ?? '') === '3';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($data['mchOrderNo'] ?? '')),
            'paid_amount' => $success ? $this->yuanToCents($data['amount'] ?? null, '汇联支付回调金额') : null,
            'message' => (string) ($data['state'] ?? ''),
            'chan_order_no' => (string) ($data['mchOrderNo'] ?? ''),
            'chan_trade_no' => (string) ($data['payOrderNo'] ?? ''),
            'channel_status' => (string) ($data['state'] ?? ''),
        ];
    }

    /**
     * 返回汇联成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回汇联失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'sign fail';
    }

    /**
     * 将渠道元金额转换为整数分。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
     */
    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $identity = $this->directPaymentIdentityData($order, $payType === 'wxpay' ? 'wechat_oauth' : 'alipay_oauth');
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('汇联 JSAPI 支付缺少 ' . $identity['identity_field'] . '，请先完成身份授权', 40200);
        }
        $product = $payType === 'wxpay' ? 'WECHAT' : 'ALIPAY';
        $extra = ['userId' => $identity['identity']];
        if ($payType === 'wxpay') {
            $extra['subAppid'] = $identity['app_id'];
        }

        try {
            $data = $this->client()->execute('/openapi/pay/create', $this->basePayload($order) + [
                'payType' => $product,
                'paySubType' => 'JSAPI',
                'extra' => $extra,
            ]);
        } catch (HlpaySdkException $e) {
            throw new PaymentException('汇联支付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', $payType, $product . '_JSAPI', 'pay/create', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 渠道下单参数
     */
    private function basePayload(array $order): array
    {
        $payload = [
            'sceneType' => $this->configText('scene_type') ?: '1',
            'mchOrderNo' => (string) $order['pay_no'],
            'amount' => FormatHelper::amount((int) $order['amount']),
            'clientIp' => (string) $order['client_ip'],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notifyUrl' => (string) $order['callback_url'],
            'redirectUrl' => (string) $order['return_url'],
        ];
        if ($this->configText('channel_code') !== '') {
            $payload['channelCode'] = $this->configText('channel_code');
        }

        return $payload;
    }

    /**
     * 包装标准支付结果。
     *
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 汇联产品编码
     * @param string $action 渠道接口动作
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['mchOrderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['payOrderNo'] ?? ''),
        ]);
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return HlpayClient
     */
    private function client(): HlpayClient
    {
        if ($this->client === null) {
            $this->client = new HlpayClient([
                'app_id' => $this->configText('app_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'sub_sn' => $this->configText('sub_sn'),
            ]);
        }

        return $this->client;
    }

    /**
     * 获取字符串配置。
     *
     * @param string $key 配置键
     * @return string 配置值
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
