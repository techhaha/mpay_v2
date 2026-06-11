<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\jlpay\JlpayClient;
use app\common\sdk\jlpay\JlpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 嘉联支付 API 插件。
 */
class JlpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_MICROPAY = 'micropay';
    private const PRODUCT_OPEN_TRANS_WAPH5PAY = 'open/trans/waph5pay';
    private const PRODUCT_OPEN_TRANS_OFFICIALPAY = 'open/trans/officialpay';
    private const PRODUCT_OPEN_TRANS_UNIONJSPAY = 'open/trans/unionjspay';
    private const PRODUCT_QRCODEPAY = 'qrcodepay';

    private ?JlpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'jlpay_api',
        'name' => '嘉联支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.jlpay.com/',
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
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '嘉联公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '嘉联公钥不能为空']]],
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'input', 'field' => 'term_no', 'title' => '终端号', 'value' => '', 'validate' => [['required' => true, 'message' => '终端号不能为空']]],
            ['type' => 'switch', 'field' => 'is_test', 'title' => '测试环境', 'value' => false],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_MICROPAY => '付款码支付',
                self::PRODUCT_OPEN_TRANS_WAPH5PAY => '支付宝 JSAPI/H5',
                self::PRODUCT_OPEN_TRANS_OFFICIALPAY => '微信 JSAPI',
                self::PRODUCT_OPEN_TRANS_UNIONJSPAY => '银联 JSAPI',
                self::PRODUCT_QRCODEPAY => '扫码支付',
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
        return $this->executeDirectPaymentProduct($order, [

            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_MICROPAY,
                    'wxpay' => self::PRODUCT_MICROPAY,
                    'bank' => self::PRODUCT_MICROPAY,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_OPEN_TRANS_WAPH5PAY,
                    'wxpay' => self::PRODUCT_OPEN_TRANS_OFFICIALPAY,
                    'bank' => self::PRODUCT_OPEN_TRANS_UNIONJSPAY,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_QRCODEPAY,
                    'wxpay' => self::PRODUCT_QRCODEPAY,
                    'bank' => self::PRODUCT_QRCODEPAY,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ], '嘉联支付');
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order): array
    {
        $payType = $this->channelPayType((string) $order['pay_type_code']);
        try {
            $data = $this->client()->execute('/open/trans/qrcodepay', $this->basePayload($order) + [
                'pay_type' => $payType,
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentException('嘉联支付下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], 'qrcodepay', 'qrcodepay', ['qrcode' => (string) ($data['code_url'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 查询订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->execute('/open/trans/chnquery', [
                'mch_id' => $this->configText('mch_id'),
                'transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'message' => $e->getMessage()];
        }

        $status = (string) ($data['status'] ?? '') === '2' ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING;
        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['transaction_id'] ?? ''),
            'channel_status' => (string) ($data['status'] ?? ''),
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
            $data = $this->client()->execute('/open/trans/cancel', [
                'mch_id' => $this->configText('mch_id'),
                'out_trade_no' => date('YmdHis') . random_int(1000, 9999),
                'ori_transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
                'mch_create_ip' => (string) ($order['client_ip'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return ['success' => true, 'msg' => '关单成功', 'raw_data' => $data];
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
            $data = $this->client()->execute('/open/trans/refund', [
                'mch_id' => $this->configText('mch_id'),
                'out_trade_no' => (string) $order['refund_no'],
                'ori_transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
                'total_fee' => (string) (int) $order['refund_amount'],
                'mch_create_ip' => (string) ($order['client_ip'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['transaction_id'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['total_fee'] ?? $order['refund_amount']),
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
        $raw = $request->rawBody();
        if (!$this->client()->verifyNotify($request->path(), $raw, $request->header())) {
            throw new PaymentException('嘉联支付回调验签失败', 40200);
        }

        $payload = (array) json_decode($raw, true);
        $success = (string) ($payload['status'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['status'] ?? ''),
            'channel_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'channel_trade_no' => (string) ($payload['transaction_id'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回嘉联成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return json_encode(['ret_code' => '00000'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 返回嘉联失败应答。
     */
    public function notifyFail(): string|Response
    {
        return json_encode(['ret_code' => '00001', 'ret_msg' => 'fail'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $payType = (string) $order['pay_type_code'];
        $path = match ($payType) {
            'wxpay' => '/open/trans/officialpay',
            'bank' => '/open/trans/unionjspay',
            default => '/open/trans/waph5pay',
        };
        $payload = $this->basePayload($order) + ['pay_type' => $this->channelPayType($payType)];
        if ($payType === 'wxpay') {
            $payload['open_id'] = (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? '');
            $payload['sub_appid'] = (string) ($payment['sub_appid'] ?? '');
        } elseif ($payType === 'bank') {
            $payload['user_id'] = (string) ($payment['sub_openid'] ?? '');
            $payload['app_up_identifier'] = (string) ($payment['app_up_identifier'] ?? '');
            $payload['user_auth_code'] = (string) ($payment['user_auth_code'] ?? '');
            $payload['qr_code'] = (string) $order['return_url'];
        } else {
            $payload['buyer_id'] = (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '');
        }

        try {
            $data = $this->client()->execute($path, $payload);
        } catch (JlpaySdkException $e) {
            throw new PaymentException('嘉联JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['pay_info'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult($payType === 'bank' ? 'jump' : 'jsapi', $payType, ltrim($path, '/'), ltrim($path, '/'), $payType === 'bank' ? ['url' => (string) $payInfo, 'raw' => $data] : ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function scanPay(array $order): array
    {
        try {
            $data = $this->client()->execute('/open/trans/micropay', $this->basePayload($order) + [
                'auth_code' => (string) ($order['extra']['payment']['auth_code'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentException('嘉联付款码下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('ok', (string) $order['pay_type_code'], 'micropay', 'micropay', ['raw' => $data], $data, $order);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        return [
            'mch_id' => $this->configText('mch_id'),
            'term_no' => $this->configText('term_no'),
            'out_trade_no' => (string) $order['pay_no'],
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'attach' => (string) $order['subject'],
            'total_fee' => (string) (int) $order['amount'],
            'notify_url' => (string) $order['callback_url'],
            'mch_create_ip' => (string) $order['client_ip'],
        ];
    }

    /**
     * 支付方式映射。
     */
    private function channelPayType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'wxpay',
            'bank' => 'unionpay',
            default => 'alipay',
        };
    }

    /**
     * 包装标准支付结果。
     *
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): JlpayClient
    {
        if ($this->client === null) {
            $this->client = new JlpayClient([
                'app_id' => $this->configText('app_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'is_test' => $this->getConfig('is_test', false) ? '1' : '0',
            ]);
        }

        return $this->client;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
