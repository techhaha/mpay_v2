<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\yseqt\YseqtClient;
use app\common\sdk\yseqt\YseqtSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 银盛 e企通支付 API 插件。
 */
class YseqtApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_CODE_26 = '26';
    private const PRODUCT_CODE_28 = '28';
    private const PRODUCT_CODE_30 = '30';
    private const PRODUCT_CODE_29H5 = '29h5';
    private const PRODUCT_CODE_29_URL_SCHEME = '29UrlScheme';
    private const PRODUCT_CODE_1903000 = '1903000';
    private const PRODUCT_CODE_9001002 = '9001002';

    private ?YseqtClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'yseqt_api',
        'name' => '银盛e企通支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.ysepay.com/',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'bank'],
        'transfer_types' => ['bank'],
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
            ['type' => 'input', 'field' => 'src_merchant_no', 'title' => '服务商商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '服务商商户号不能为空']]],
            ['type' => 'input', 'field' => 'payee_merchant_no', 'title' => '收款商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '收款商户号不能为空']]],
            ['type' => 'password', 'field' => 'private_cert_password', 'title' => '私钥证书密码', 'value' => '', 'validate' => [['required' => true, 'message' => '私钥证书密码不能为空']]],
            ['type' => 'input', 'field' => 'platform_cert_path', 'title' => '银盛公钥证书路径', 'value' => ''],
            ['type' => 'input', 'field' => 'private_cert_path', 'title' => '商户PFX证书路径', 'value' => ''],
            ['type' => 'input', 'field' => 'gateway_url', 'title' => '自定义网关', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_CODE_26 => '支付宝 JSAPI',
                self::PRODUCT_CODE_28 => '微信 JSAPI',
                self::PRODUCT_CODE_30 => '银联 JSAPI',
                self::PRODUCT_CODE_29H5 => '微信 H5',
                self::PRODUCT_CODE_29_URL_SCHEME => '微信 URL Scheme',
                self::PRODUCT_CODE_1903000 => '支付宝扫码',
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
        $payType = (string) $order['pay_type_code'];

        return $this->executeDirectPaymentProduct($order, [

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_26,
                    'wxpay' => self::PRODUCT_CODE_28,
                    'bank' => self::PRODUCT_CODE_30,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'h5' => [
                'products' => [
                    'wxpay' => self::PRODUCT_CODE_29H5,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                    ? $this->wxCashierPay($order, '29h5')
                    : throw new PaymentException('银盛e企通当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'jump' => [
                'products' => [
                    'wxpay' => self::PRODUCT_CODE_29H5,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->wxCashierPay($order, '29h5')
                : throw new PaymentException('银盛e企通当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'urlscheme' => [
                'products' => [
                    'wxpay' => self::PRODUCT_CODE_29_URL_SCHEME,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->wxCashierPay($order, '29UrlScheme')
                : throw new PaymentException('银盛e企通当前支付方式不支持URL Scheme产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_1903000,
                    'bank' => self::PRODUCT_CODE_9001002,
                ],
                'handler' => fn (): array => $this->scanPay($order, $payType),
            ],
        ], '银盛e企通');
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $payType 支付方式
     * @return array<string, mixed>
     */
    private function scanPay(array $order, string $payType): array
    {
        if ($payType === 'wxpay') {
            throw new PaymentException('银盛e企通当前支付方式不支持微信扫码产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']);
        }

        $bankType = $payType === 'bank' ? '9001002' : '1903000';
        try {
            $data = $this->client()->execute('scanPay', $this->basePayload($order) + [
                'bankType' => $bankType,
            ]);
        } catch (YseqtSdkException $e) {
            throw new PaymentException('银盛e企通下单失败：' . $e->getMessage(), 40200);
        }

        if (!in_array((string) ($data['subCode'] ?? ''), ['COM000', 'COM004'], true)) {
            throw new PaymentException((string) ($data['subMsg'] ?? '银盛e企通下单失败'), 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $bankType, 'scanPay', ['qrcode' => (string) ($data['qrCode'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 银盛 e企通旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '银盛e企通插件暂不支持主动查单'];
    }

    /**
     * 银盛 e企通旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '银盛e企通插件暂不支持关单'];
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
            $data = $this->client()->execute('refund', [
                'requestNo' => (string) $order['refund_no'],
                'origRequestNo' => (string) $order['pay_no'],
                'origTradeSn' => (string) ($order['chan_trade_no'] ?? ''),
                'amount' => FormatHelper::amount((int) $order['refund_amount']),
                'reason' => '申请退款',
                'isDivision' => 'N',
            ]);
        } catch (YseqtSdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        if (!in_array((string) ($data['subCode'] ?? ''), ['COM000', 'COM004'], true)) {
            return ['success' => false, 'msg' => (string) ($data['subMsg'] ?? '退款失败'), 'raw_data' => $data];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refundSn'] ?? $order['refund_no']),
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
        $payload = $request->post();
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('银盛e企通回调验签失败', 40200);
        }

        $biz = $payload['bizResponseJson'] ?? [];
        if (is_string($biz)) {
            $biz = json_decode($biz, true);
        }
        $biz = (array) $biz;
        $success = (string) ($biz['state'] ?? '') === 'SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($biz['state'] ?? ''),
            'channel_order_no' => (string) ($biz['requestNo'] ?? ''),
            'channel_trade_no' => (string) ($biz['tradeSn'] ?? ''),
            'channel_status' => (string) ($biz['state'] ?? ''),
        ];
    }

    /**
     * 返回银盛 e企通成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回银盛 e企通失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 微信聚合收银台。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function wxCashierPay(array $order, string $payMode): array
    {
        try {
            $data = $this->client()->execute('cashierPay', $this->basePayload($order) + [
                'payMode' => $payMode,
                'isFastPay' => 'Y',
            ]);
        } catch (YseqtSdkException $e) {
            throw new PaymentException('银盛e企通微信下单失败：' . $e->getMessage(), 40200);
        }

        $page = $payMode === '29UrlScheme' ? 'urlscheme' : 'jump';
        $key = $page === 'urlscheme' ? 'urlscheme' : 'url';

        return $this->payResult($page, 'wxpay', $payMode, 'cashierPay', [$key => (string) ($data['payData'] ?? ''), 'raw' => $data], $data, $order);
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
        $payMode = match ($payType) {
            'wxpay' => '28',
            'bank' => '30',
            default => '26',
        };
        $params = $this->basePayload($order) + [
            'bankType' => $payType === 'bank' ? '9001002' : ($payType === 'wxpay' ? '1902000' : '1903000'),
            'payMode' => $payMode,
        ];
        if ($payType === 'wxpay') {
            $params['wxAppId'] = (string) ($payment['sub_appid'] ?? '');
            $params['wxOpenId'] = (string) ($payment['sub_openid'] ?? '');
        } elseif ($payType === 'bank') {
            $params['unionUserId'] = (string) ($payment['sub_openid'] ?? '');
        } else {
            $params['alipayId'] = (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '');
        }

        try {
            $data = $this->client()->execute('jsPay', $params);
        } catch (YseqtSdkException $e) {
            throw new PaymentException('银盛e企通JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        if ($payType === 'bank') {
            $payData = json_decode((string) ($data['payData'] ?? ''), true);
            return $this->payResult('jump', $payType, $payMode, 'jsPay', ['url' => (string) ($payData['redirectUrl'] ?? ''), 'raw' => $data], $data, $order);
        }

        $payInfo = json_decode((string) ($data['payData'] ?? ''), true);
        $payInfo = is_array($payInfo) ? $payInfo : ['tradeNO' => (string) ($data['payData'] ?? '')];
        $payInfo['raw'] = $data;

        return $this->payResult('jsapi', $payType, $payMode, 'jsPay', $payInfo, $data, $order);
    }

    /**
     * 构造 e企通支付公共参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        return [
            'requestNo' => (string) $order['pay_no'],
            'payeeMerchantNo' => $this->configText('payee_merchant_no'),
            'orderDesc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'amount' => FormatHelper::amount((int) $order['amount']),
            'notifyUrl' => (string) $order['callback_url'],
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
            'chan_order_no' => (string) ($data['requestNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['tradeSn'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): YseqtClient
    {
        if ($this->client === null) {
            $base = base_path(false) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'sdk' . DIRECTORY_SEPARATOR . 'yseqt' . DIRECTORY_SEPARATOR . 'cert';
            $this->client = new YseqtClient([
                'src_merchant_no' => $this->configText('src_merchant_no'),
                'private_cert_password' => $this->configText('private_cert_password'),
                'platform_cert_path' => $this->configText('platform_cert_path') ?: $base . DIRECTORY_SEPARATOR . 'businessgate.cer',
                'private_cert_path' => $this->configText('private_cert_path') ?: $base . DIRECTORY_SEPARATOR . 'client.pfx',
                'gateway_url' => $this->configText('gateway_url'),
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
