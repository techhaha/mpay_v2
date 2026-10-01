<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\xorpay\XorpayClient;
use app\common\sdk\xorpay\XorpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * XorPay 支付插件。
 *
 * 负责托管微信收银台、支付宝/微信扫码、主动查单、退款及支付成功通知适配。
 * 微信收银台由 XorPay 托管并在其页面获取 openid，不属于 MPAY 微信官方 JSAPI
 * 身份产品；插件不声明 PaymentIdentityRequirementInterface。
 */
class XorpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_WECHAT_CASHIER = 'wechat_cashier';
    private const PRODUCT_ALIPAY = 'alipay';
    private const PRODUCT_WX_NATIVE = 'wx_native';

    private ?XorpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'xorpay_api',
        'name' => 'XorPay支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.1.0',
        'pay_types' => ['alipay', 'wxpay'],
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
                'field' => 'app_secret',
                'title' => 'AppSecret',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'AppSecret不能为空'],
                ],
            ],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_WECHAT_CASHIER => '微信收银台',
                self::PRODUCT_ALIPAY => '支付宝扫码',
                self::PRODUCT_WX_NATIVE => '微信扫码',
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
                    'wxpay' => self::PRODUCT_WECHAT_CASHIER,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                    ? $this->wechatCashier($order)
                    : throw new PaymentProductUnavailableException('XorPay 当前支付方式不支持JSAPI产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WX_NATIVE,
                ],
                'handler' => fn (): array => $this->qrcodePay($order, $payType),
            ],
        ], 'XorPay');
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
        $channelPayType = $payType === 'wxpay' ? 'native' : 'alipay';
        $product = $payType === 'wxpay' ? self::PRODUCT_WX_NATIVE : self::PRODUCT_ALIPAY;
        try {
            $data = $this->client()->pay([
                'name' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'pay_type' => $channelPayType,
                'price' => FormatHelper::amount((int) $order['amount']),
                'order_id' => (string) $order['pay_no'],
                'notify_url' => (string) $order['callback_url'],
            ]);
        } catch (XorpaySdkException $e) {
            $this->throwSdkFailure('下单', $e);
        }

        $info = $data['info'] ?? null;
        $qrcode = is_array($info) ? trim((string) ($info['qr'] ?? '')) : '';
        $xorpayOrderId = is_array($info) ? trim((string) ($info['aoid'] ?? '')) : '';
        if ($qrcode === '' || $xorpayOrderId === '' || strlen($xorpayOrderId) > 64) {
            throw new PaymentUncertainException('XorPay 下单成功响应缺少二维码或平台订单号', 40200);
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'qrcode',
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'api.pay',
            'pay_params' => [
                'qrcode' => $qrcode,
            ],
            'chan_order_no' => $xorpayOrderId,
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 按 XorPay 平台订单号查询支付状态。
     *
     * 查单响应不返回可独立核对的金额或交易号时沿用本地订单上下文，不据此补造渠道事实。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        $xorpayOrderId = trim((string) ($order['chan_order_no'] ?? ''));
        if ($xorpayOrderId === '') {
            throw new PaymentException('XorPay 主动查单缺少平台订单号', 40200);
        }

        try {
            $data = $this->client()->query($xorpayOrderId);
        } catch (XorpaySdkException $e) {
            throw new PaymentUncertainException('XorPay 查单结果不确定：' . $e->getMessage(), 40200, [
                'channel_error_code' => $e->channelStatus(),
            ]);
        }

        $channelStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $status = match ($channelStatus) {
            'success', 'payed' => PaymentPluginStatusConstant::SUCCESS,
            'new' => PaymentPluginStatusConstant::PENDING,
            'expire' => PaymentPluginStatusConstant::CLOSED,
            'not_exist', 'fee_error' => PaymentPluginStatusConstant::UNKNOWN,
            default => PaymentPluginStatusConstant::UNKNOWN,
        };

        return [
            'status' => $status,
            'pay_no' => (string) $order['pay_no'],
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? (int) $order['amount']
                : null,
            'chan_order_no' => $xorpayOrderId,
            'chan_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
            'channel_status' => $channelStatus,
            'message' => $this->queryStatusMessage($channelStatus),
        ];
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('XorPay 插件暂不支持关单', 40200);
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
            throw new PaymentException('XorPay 退款缺少渠道交易号', 40200);
        }

        // XorPay 退款路径明确使用 aoid；MPAY 将其保存为渠道订单号。
        $xorpayOrderId = trim((string) ($order['chan_order_no'] ?? ''));
        if ($xorpayOrderId === '') {
            throw new PaymentException('XorPay 退款缺少平台订单号', 40200);
        }

        try {
            $data = $this->client()->refund(
                $xorpayOrderId,
                FormatHelper::amount((int) $order['refund_amount'])
            );
        } catch (XorpaySdkException $e) {
            $this->throwSdkFailure('退款', $e);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) $order['refund_amount'],
            'chan_refund_no' => '',
            'channel_status' => (string) $data['status'],
            'message' => 'XorPay 已明确返回退款成功',
        ];
    }

    /**
     * 解析支付回调。
     *
     * 官方通知仅在支付成功后发送；参数验签后仍严格校验订单号长度、元金额、交易号和支付时间。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付成功通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $request->post();
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('XorPay 回调验签失败', 40200);
        }

        // 官方支付通知只在支付成功后发送，协议没有独立成功状态字段。
        if (array_key_exists('status', $payload)
            && strtolower(trim((string) $payload['status'])) !== PaymentPluginStatusConstant::SUCCESS
        ) {
            throw new PaymentException('XorPay 回调携带非成功状态', 40200);
        }

        $payNo = trim((string) $payload['order_id']);
        $xorpayOrderId = trim((string) $payload['aoid']);
        if (strlen($payNo) > 64 || strlen($xorpayOrderId) > 64) {
            throw new PaymentException('XorPay 回调订单号长度无效', 40200);
        }

        $detail = json_decode((string) ($payload['detail'] ?? ''), true);
        $channelTradeNo = is_array($detail) ? trim((string) ($detail['transaction_id'] ?? '')) : '';
        if ($channelTradeNo === '' || strlen($channelTradeNo) > 64) {
            throw new PaymentException('XorPay 回调缺少有效渠道交易号', 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => $payNo,
            'paid_amount' => $this->yuanToCents($payload['pay_price'] ?? null, 'XorPay 回调金额'),
            'message' => '支付成功',
            'chan_order_no' => $xorpayOrderId,
            'chan_trade_no' => $channelTradeNo,
            'channel_status' => 'payment_success',
            'paid_at' => $this->payTime((string) $payload['pay_time']),
        ];
    }

    /**
     * 返回 XorPay 成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回 XorPay 失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 将渠道元金额严格换算为分，拒绝负数及超过两位的小数。
     *
     * @param mixed $value 渠道元金额原值
     * @param string $field 用于异常提示的字段名称
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
     * 微信公众号环境使用 XorPay 收银台表单。
     *
     * 所有隐藏字段和表单地址都经过 HTML 属性转义，再由承接页自动提交到托管收银台。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准 HTML 待支付结果
     */
    private function wechatCashier(array $order): array
    {
        try {
            $client = $this->client();
            $payload = $client->cashierPayload([
                'name' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'pay_type' => 'jsapi',
                'price' => FormatHelper::amount((int) $order['amount']),
                'order_id' => (string) $order['pay_no'],
                'notify_url' => (string) $order['callback_url'],
                'return_url' => (string) $order['return_url'],
            ]);
        } catch (XorpaySdkException $e) {
            $this->throwSdkFailure('收银台参数生成', $e);
        }

        $html = '<form action="' . $this->escapeHtmlAttribute($client->cashierUrl())
            . '" method="post" accept-charset="UTF-8" id="xorpay-cashier-form">';
        foreach ($payload as $key => $value) {
            $html .= '<input type="hidden" name="' . $this->escapeHtmlAttribute((string) $key)
                . '" value="' . $this->escapeHtmlAttribute((string) $value) . '">';
        }
        $html .= '</form><script>document.getElementById("xorpay-cashier-form").submit();</script>';

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'html',
            'pay_type' => 'wxpay',
            'pay_product' => self::PRODUCT_WECHAT_CASHIER,
            'pay_action' => 'api.cashier',
            'pay_params' => [
                'html' => $html,
            ],
            'chan_order_no' => '',
            'chan_trade_no' => '',
        ]);
    }

    /**
     * 获取复用应用凭据初始化的 SDK 客户端。
     */
    private function client(): XorpayClient
    {
        if ($this->client === null) {
            $this->client = new XorpayClient([
                'app_id' => $this->configText('app_id'),
                'app_secret' => $this->configText('app_secret'),
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
        return trim((string) $this->getConfig($key, ''));
    }

    /**
     * 转义托管表单的 HTML 属性值。
     *
     * @param string $value 原始属性值
     */
    private function escapeHtmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 严格校验 XorPay 支付时间。
     *
     * @param string $value 渠道支付时间
     */
    private function payTime(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            throw new PaymentException('XorPay 回调支付时间格式无效', 40200);
        }

        return $value;
    }

    /**
     * 获取官方查单状态的受控说明。
     *
     * @param string $status XorPay 查单状态
     */
    private function queryStatusMessage(string $status): string
    {
        return match ($status) {
            'success' => '订单已支付且通知成功',
            'payed' => '订单已支付但通知尚未成功',
            'new' => '订单尚未支付',
            'expire' => '订单已过期',
            'not_exist' => 'XorPay 未找到订单',
            'fee_error' => 'XorPay 手续费扣除失败，支付结果需人工核对',
            default => 'XorPay 返回未识别的查询状态',
        };
    }

    /**
     * 按 SDK 确定性分类转换支付变更异常。
     *
     * @param string $action 支付变更动作
     * @param XorpaySdkException $e SDK 异常
     *
     * @throws PaymentDefinitiveException 上游明确拒绝时抛出
     * @throws PaymentUncertainException 上游是否受理无法确认时抛出
     */
    private function throwSdkFailure(string $action, XorpaySdkException $e): never
    {
        $data = $e->channelStatus() === ''
            ? []
            : ['channel_error_code' => $e->channelStatus()];
        if ($e->isUncertain()) {
            throw new PaymentUncertainException('XorPay ' . $action . '结果不确定：' . $e->getMessage(), 40200, $data);
        }

        throw new PaymentDefinitiveException('XorPay ' . $action . '被拒绝：' . $e->getMessage(), 40200, $data);
    }
}
