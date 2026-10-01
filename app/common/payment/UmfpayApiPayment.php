<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\umfpay\UmfpayClient;
use app\common\sdk\umfpay\UmfpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 联动优势支付 API 插件。
 *
 * 负责微信公众号、支付宝/微信/银联扫码产品的下单、退款及带签名应答的异步通知适配。
 * 当前协议未接入主动查单与关单能力。
 */
class UmfpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_PUBLICNUMBER_AND_VERTICALCODE = 'publicnumber_and_verticalcode';
    private const PRODUCT_ALIPAY = 'ALIPAY';
    private const PRODUCT_WECHAT = 'WECHAT';
    private const PRODUCT_UNION = 'UNION';

    private ?UmfpayClient $client = null;

    /**
     * 最近一次回调参数，用于生成联动优势要求的签名应答。
     *
     * @var array<string, mixed>
     */
    private array $lastNotifyPayload = [];

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'umfpay_api',
        'name' => '联动优势支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://xy.umfintech.com/',
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
            ['type' => 'input', 'field' => 'mer_id', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥 PEM', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥 PEM', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_PUBLICNUMBER_AND_VERTICALCODE => '微信公众号支付',
                self::PRODUCT_ALIPAY => '支付宝扫码',
                self::PRODUCT_WECHAT => '微信扫码',
                self::PRODUCT_UNION => '银联扫码',
            ]),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    public function pay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];

        return $this->executeDirectPaymentProduct($order, [

            'jsapi' => [
                'products' => [
                    'wxpay' => self::PRODUCT_PUBLICNUMBER_AND_VERTICALCODE,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->jsapiPay($order)
                : throw new PaymentProductUnavailableException('联动优势当前支付方式不支持JSAPI产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHAT,
                    'bank' => self::PRODUCT_UNION,
                ],
                'handler' => fn (): array => $this->qrcodePay($order, $payType),
            ],
        ], '联动优势');
    }

    /**
     * 微信公众号跳转支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准跳转待支付结果
     */
    private function jsapiPay(array $order): array
    {
        $url = $this->client()->payUrl($this->basePayload($order) + [
            'service' => 'publicnumber_and_verticalcode',
            'ret_url' => (string) $order['return_url'],
            'is_public_number' => 'Y',
        ]);

        return $this->payResult('jump', 'wxpay', 'publicnumber_and_verticalcode', 'publicnumber_and_verticalcode', ['url' => $url], [], $order);
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 标准支付方式代码
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function qrcodePay(array $order, string $payType): array
    {
        $scanType = match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNION',
            default => 'ALIPAY',
        };

        try {
            $data = $this->client()->submit($this->basePayload($order) + [
                'service' => 'active_scancode_order_new',
                'scancode_type' => $scanType,
                'mer_flag' => 'KMER',
                'consumer_id' => str_replace('.', '', (string) $order['client_ip']),
            ]);
        } catch (UmfpaySdkException $e) {
            throw new PaymentException('联动优势下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['ret_code'] ?? '') !== '0000') {
            throw new PaymentException('联动优势下单失败：' . (string) ($data['ret_msg'] ?? ''), 40200, ['response' => $data]);
        }

        $qrcode = base64_decode((string) ($data['bank_payurl'] ?? ''), true) ?: '';
        if ($qrcode === '') {
            throw new PaymentException('联动优势未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $scanType, 'active_scancode_order_new', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('联动优势插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('联动优势插件暂不支持关单', 40200);
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
            $data = $this->client()->submit([
                'service' => 'mer_refund',
                'refund_no' => (string) $order['refund_no'],
                'order_id' => (string) $order['pay_no'],
                'mer_date' => substr((string) $order['pay_no'], 3, 8),
                'org_amount' => (string) (int) $order['amount'],
                'refund_amount' => (string) (int) $order['refund_amount'],
            ]);
        } catch (UmfpaySdkException $e) {
            throw new PaymentUncertainException('联动优势退款结果不确定：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['ret_code'] ?? '') !== '0000') {
            throw new PaymentDefinitiveException((string) ($data['ret_msg'] ?? '联动优势退款失败'), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) ($data['refund_amt'] ?? $order['refund_amount']),
            'chan_refund_no' => (string) ($data['refund_no'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析支付回调。
     *
     * 联动优势以查询参数通知；原始参数会保留到当前处理实例，用于生成协议要求的签名 HTML 应答。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $request->get();
        $this->lastNotifyPayload = $payload;
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('联动优势回调验签失败', 40200);
        }

        $success = (string) ($payload['trade_state'] ?? '') === 'TRADE_SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($payload['order_id'] ?? '')),
            'paid_amount' => $success ? $this->integerCents($payload['amount'] ?? null, '联动优势回调金额') : null,
            'message' => (string) ($payload['trade_state'] ?? ''),
            'chan_order_no' => (string) ($payload['order_id'] ?? ''),
            'chan_trade_no' => (string) ($payload['trade_no'] ?? ''),
            'channel_status' => (string) ($payload['trade_state'] ?? ''),
        ];
    }

    /**
     * 使用最近一次已解析通知参数生成联动优势签名成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return $this->client()->responseHtml($this->lastNotifyPayload, '0000', 'success');
    }

    /**
     * 使用最近一次已解析通知参数生成联动优势签名失败应答。
     */
    public function notifyFail(): string|Response
    {
        return $this->client()->responseHtml($this->lastNotifyPayload, '0001', 'fail');
    }

    /**
     * 解析渠道以整数分表示的金额，拒绝小数及非数字内容。
     *
     * @param mixed $value 渠道金额原值
     * @param string $field 用于异常提示的字段名称
     */
    private function integerCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+$/', $text) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return (int) $text;
    }

    /**
     * 构造联动优势各支付产品共享的下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 上游下单请求参数
     */
    private function basePayload(array $order): array
    {
        return [
            'notify_url' => (string) $order['callback_url'],
            'goods_inf' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'order_id' => (string) $order['pay_no'],
            'mer_date' => date('Ymd'),
            'amount' => (string) (int) $order['amount'],
            'user_ip' => (string) $order['client_ip'],
        ];
    }

    /**
     * 将上游支付凭据包装为标准待支付结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 联动优势产品代码
     * @param string $action 支付动作标识
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准待支付结果
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['order_id'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ]);
    }

    /**
     * 获取复用商户私钥与平台公钥初始化的 SDK 客户端。
     */
    private function client(): UmfpayClient
    {
        if ($this->client === null) {
            $this->client = new UmfpayClient([
                'mer_id' => $this->configText('mer_id'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
            ]);
        }

        return $this->client;
    }

    /**
     * 读取字符串配置，缺失时返回空字符串。
     *
     * @param string $key 配置键
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
