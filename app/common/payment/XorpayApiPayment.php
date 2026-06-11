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
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * XorPay 支付插件。
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
        'version' => '1.0.0',
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
     * @return array<string, mixed>
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
                : throw new PaymentException('XorPay 当前支付方式不支持JSAPI产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
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
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order, string $payType): array
    {
        $channelPayType = $payType === 'wxpay' ? 'native' : 'alipay';
        $product = $payType === 'wxpay' ? 'wx_native' : 'alipay';
        try {
            $data = $this->client()->pay([
                'name' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'pay_type' => $channelPayType,
                'price' => FormatHelper::amount((int) $order['amount']),
                'order_id' => (string) $order['pay_no'],
                'notify_url' => (string) $order['callback_url'],
            ]);
        } catch (XorpaySdkException $e) {
            throw new PaymentException('XorPay 下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['info']['qr'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('XorPay 未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'api.pay',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * XorPay 旧插件未提供主动查单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => 'XorPay 插件暂不支持主动查单',
        ];
    }

    /**
     * XorPay 旧插件未提供关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => 'XorPay 插件暂不支持关单',
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
            $data = $this->client()->refund(
                (string) ($order['chan_trade_no'] ?? ''),
                FormatHelper::amount((int) $order['refund_amount'])
            );
        } catch (XorpaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['info']['refund_id'] ?? $order['refund_no']),
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
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('XorPay 回调验签失败', 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'message' => '支付成功',
            'channel_order_no' => (string) ($payload['order_id'] ?? ''),
            'channel_trade_no' => (string) ($payload['aoid'] ?? ''),
            'channel_status' => 'success',
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
     * 微信公众号环境使用 XorPay 收银台表单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function wechatCashier(array $order): array
    {
        $payload = $this->client()->cashierPayload([
            'name' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'pay_type' => 'jsapi',
            'price' => FormatHelper::amount((int) $order['amount']),
            'order_id' => (string) $order['pay_no'],
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) $order['return_url'],
        ]);

        $html = '<form action="https://xorpay.com/api/cashier/' . htmlspecialchars($this->configText('app_id'), ENT_QUOTES, 'UTF-8') . '" method="post" id="dopay">';
        foreach ($payload as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</form><script>document.getElementById("dopay").submit();</script>';

        return [
            'pay_page' => 'html',
            'pay_type' => 'wxpay',
            'pay_product' => 'wechat_cashier',
            'pay_action' => 'api.cashier',
            'pay_params' => [
                'html' => $html,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 获取 SDK 客户端。
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
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
