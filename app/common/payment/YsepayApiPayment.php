<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\ysepay\YsepayClient;
use app\common\sdk\ysepay\YsepaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 银盛支付 API 插件。
 */
class YsepayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WEIXIN_JSAPI = 'weixin_jsapi';
    private const PRODUCT_CUPMULAPP = 'cupmulapp';
    private const PRODUCT_CODE_1903000 = '1903000';
    private const PRODUCT_CODE_1902000 = '1902000';
    private const PRODUCT_CODE_9001002 = '9001002';

    private ?YsepayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'ysepay_api',
        'name' => '银盛支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.ysepay.com/',
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
            ['type' => 'input', 'field' => 'partner_id', 'title' => '服务商商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '服务商商户号不能为空']]],
            ['type' => 'input', 'field' => 'seller_id', 'title' => '收款商户号', 'value' => ''],
            ['type' => 'input', 'field' => 'business_code', 'title' => '业务代码', 'value' => '', 'validate' => [['required' => true, 'message' => '业务代码不能为空']]],
            ['type' => 'password', 'field' => 'private_cert_password', 'title' => '私钥证书密码', 'value' => '', 'validate' => [['required' => true, 'message' => '私钥证书密码不能为空']]],
            ['type' => 'input', 'field' => 'platform_cert_path', 'title' => '银盛公钥证书路径', 'value' => ''],
            ['type' => 'input', 'field' => 'private_cert_path', 'title' => '商户PFX证书路径', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_ALIPAY_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_ALIPAY_H5 => '支付宝 H5',
                self::PRODUCT_WEIXIN_JSAPI => '微信 JSAPI/小程序',
                self::PRODUCT_CUPMULAPP => '银联 JSAPI',
                self::PRODUCT_CODE_1903000 => '支付宝扫码',
                self::PRODUCT_CODE_1902000 => '微信扫码',
                self::PRODUCT_CODE_9001002 => '银联扫码',
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

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WEIXIN_JSAPI,
                    'bank' => self::PRODUCT_CUPMULAPP,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'h5' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5Pay($order),
            ],

            'jump' => [
                'products' => ['alipay' => self::PRODUCT_ALIPAY_H5],
                'handler' => fn (): array => $this->alipayH5Pay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_1903000,
                    'wxpay' => self::PRODUCT_CODE_1902000,
                    'bank' => self::PRODUCT_CODE_9001002,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ], '银盛');
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $bankType = match ($payType) {
            'wxpay' => '1902000',
            'bank' => '9001002',
            default => '1903000',
        };

        try {
            $data = $this->client()->execute('ysepay.online.qrcodepay', $this->basePayload($order) + [
                'bank_type' => $bankType,
                'submer_ip' => (string) $order['client_ip'],
            ], ['notify_url' => (string) $order['callback_url']]);
        } catch (YsepaySdkException $e) {
            throw new PaymentException('银盛下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['source_qr_code_url'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('银盛未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $bankType, 'ysepay.online.qrcodepay', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 支付宝 H5 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function alipayH5Pay(array $order): array
    {
        try {
            $html = $this->client()->pageExecute('ysepay.online.wap.directpay.createbyuser', $this->basePayload($order) + [
                'pay_mode' => 'native',
                'bank_type' => '1903000',
            ], [
                'notify_url' => (string) $order['callback_url'],
                'return_url' => (string) $order['return_url'],
            ]);
        } catch (YsepaySdkException $e) {
            throw new PaymentException('银盛支付宝H5下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('html', 'alipay', self::PRODUCT_ALIPAY_H5, 'ysepay.online.wap.directpay.createbyuser', [
            'html' => $html,
        ], [], $order);
    }

    /**
     * 银盛开放网关旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '银盛插件暂不支持主动查单'];
    }

    /**
     * 银盛开放网关旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '银盛插件暂不支持关单'];
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
            $data = $this->client()->execute('ysepay.online.trade.refund', [
                'out_trade_no' => (string) $order['pay_no'],
                'shopdate' => date('Ymd'),
                'trade_no' => (string) ($order['chan_trade_no'] ?? ''),
                'refund_amount' => FormatHelper::amount((int) $order['refund_amount']),
                'refund_reason' => '申请退款',
                'out_request_no' => (string) $order['refund_no'],
            ]);
        } catch (YsepaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['trade_no'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['refund_amount'] ?? 0)) * 100),
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
            throw new PaymentException('银盛回调验签失败', 40200);
        }

        $success = (string) ($payload['trade_status'] ?? '') === 'TRADE_SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['trade_status'] ?? ''),
            'channel_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'channel_trade_no' => (string) ($payload['trade_no'] ?? ''),
            'channel_status' => (string) ($payload['trade_status'] ?? ''),
        ];
    }

    /**
     * 返回银盛成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回银盛失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $payment = (array) ($order['extra']['payment'] ?? []);
        if ($payType === 'bank') {
            try {
                $data = $this->client()->execute('ysepay.online.cupmulapp.qrcodepay', $this->basePayload($order) + [
                    'spbill_create_ip' => (string) $order['client_ip'],
                    'bank_type' => '9001002',
                    'userId' => (string) ($payment['sub_openid'] ?? ''),
                ], ['notify_url' => (string) $order['callback_url']]);
            } catch (YsepaySdkException $e) {
                throw new PaymentException('银盛云闪付下单失败：' . $e->getMessage(), 40200);
            }

            return $this->payResult('jump', $payType, 'cupmulapp', 'ysepay.online.cupmulapp.qrcodepay', ['url' => (string) ($data['web_url'] ?? ''), 'raw' => $data], $data, $order);
        }

        $product = $payType === 'wxpay' ? 'weixin_jsapi' : 'alipay_jsapi';
        $method = $payType === 'wxpay' ? 'ysepay.online.weixin.pay' : 'ysepay.online.alijsapi.pay';
        $params = $this->basePayload($order) + ['payer_ip' => (string) $order['client_ip']];
        if ($payType === 'wxpay') {
            $params['appid'] = (string) ($payment['sub_appid'] ?? '');
            $params['sub_openid'] = (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? '');
            $params['is_minipg'] = (string) ($payment['mini_openid'] ?? '') !== '' ? '1' : '2';
        } else {
            $params['buyer_id'] = (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '');
        }

        try {
            $data = $this->client()->execute($method, $params, ['notify_url' => (string) $order['callback_url']]);
        } catch (YsepaySdkException $e) {
            throw new PaymentException('银盛JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = json_decode((string) ($data['jsapi_pay_info'] ?? ''), true);
        $payInfo = is_array($payInfo) ? $payInfo : ['tradeNO' => (string) ($data['jsapi_pay_info'] ?? '')];
        $payInfo['raw'] = $data;

        return $this->payResult('jsapi', $payType, $product, $method, $payInfo, $data, $order);
    }

    /**
     * 构造银盛公共支付参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        return [
            'out_trade_no' => (string) $order['pay_no'],
            'shopdate' => date('Ymd'),
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'total_amount' => FormatHelper::amount((int) $order['amount']),
            'currency' => 'CNY',
            'seller_id' => $this->sellerId(),
            'timeout_express' => '2h',
            'business_code' => $this->configText('business_code'),
        ];
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
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 当前收款商户号。
     */
    private function sellerId(): string
    {
        return $this->configText('seller_id') ?: $this->configText('partner_id');
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): YsepayClient
    {
        if ($this->client === null) {
            $base = base_path(false) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'sdk' . DIRECTORY_SEPARATOR . 'ysepay' . DIRECTORY_SEPARATOR . 'cert';
            $this->client = new YsepayClient([
                'partner_id' => $this->configText('partner_id'),
                'private_cert_password' => $this->configText('private_cert_password'),
                'platform_cert_path' => $this->configText('platform_cert_path') ?: $base . DIRECTORY_SEPARATOR . 'businessgate.cer',
                'private_cert_path' => $this->configText('private_cert_path') ?: $base . DIRECTORY_SEPARATOR . 'client.pfx',
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
