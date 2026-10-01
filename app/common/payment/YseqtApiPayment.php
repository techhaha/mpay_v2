<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\yseqt\YseqtClient;
use app\common\sdk\yseqt\YseqtSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 银盛 e企通支付 API 插件。
 */
class YseqtApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_CODE_26 = '26';
    private const PRODUCT_CODE_28 = '28';
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
                self::PRODUCT_CODE_29H5 => '微信 H5',
                self::PRODUCT_CODE_29_URL_SCHEME => '微信 URL Scheme',
                self::PRODUCT_CODE_1903000 => '支付宝扫码',
                self::PRODUCT_CODE_9001002 => '银联扫码',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'alipay_oauth']),
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
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '银盛e企通');
    }

    /**
     * 28/26 使用商户身份，29h5/29UrlScheme 的机构托管授权不进入 MPAY OAuth。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 当前产品的身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_CODE_28 => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_CODE_26 => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
            default => null,
        };
    }

    /**
     * 声明已实现产品，不将银联行业码身份当作微信 openid。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, array<string, mixed>> 产品与处理器声明
     */
    private function paymentProducts(array $order): array
    {
        $payType = (string) $order['pay_type_code'];

        return [

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_26,
                    'wxpay' => self::PRODUCT_CODE_28,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'h5' => [
                'products' => [
                    'wxpay' => self::PRODUCT_CODE_29H5,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                    ? $this->wxCashierPay($order, '29h5')
                    : throw new PaymentProductUnavailableException('银盛e企通当前支付方式不支持H5产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'jump' => [
                'products' => [
                    'wxpay' => self::PRODUCT_CODE_29H5,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->wxCashierPay($order, '29h5')
                : throw new PaymentProductUnavailableException('银盛e企通当前支付方式不支持跳转产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'urlscheme' => [
                'products' => [
                    'wxpay' => self::PRODUCT_CODE_29_URL_SCHEME,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->wxCashierPay($order, '29UrlScheme')
                : throw new PaymentProductUnavailableException('银盛e企通当前支付方式不支持URL Scheme产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_CODE_1903000,
                    'bank' => self::PRODUCT_CODE_9001002,
                ],
                'handler' => fn (): array => $this->scanPay($order, $payType),
            ],
        ];
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
            throw new PaymentProductUnavailableException('银盛e企通当前支付方式不支持微信扫码产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']);
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
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银盛e企通插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('银盛e企通插件暂不支持关单', 40200);
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
            throw new PaymentUncertainException('银盛e企通退款结果不确定：' . $e->getMessage(), 40200);
        }

        if (!in_array((string) ($data['subCode'] ?? ''), ['COM000', 'COM004'], true)) {
            throw new PaymentDefinitiveException((string) ($data['subMsg'] ?? '银盛e企通退款失败'), 40200);
        }

        $refundAmount = isset($data['amount'])
            ? $this->yuanToCents($data['amount'], '银盛e企通退款金额')
            : (int) $order['refund_amount'];

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => $refundAmount,
            'chan_refund_no' => (string) ($data['refundSn'] ?? ''),
            'message' => '退款申请成功',
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
            'pay_no' => trim((string) ($biz['requestNo'] ?? '')),
            'paid_amount' => $success ? $this->yuanToCents($biz['amount'] ?? null, '银盛e企通回调金额') : null,
            'message' => (string) ($biz['state'] ?? ''),
            'chan_order_no' => (string) ($biz['requestNo'] ?? ''),
            'chan_trade_no' => (string) ($biz['tradeSn'] ?? ''),
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

    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
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
        $identity = $this->directPaymentIdentityData($order, $payType === 'alipay' ? 'alipay_oauth' : 'wechat_oauth');
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('银盛 e企通必须先取得当前产品的用户身份', 40200);
        }
        $payMode = $payType === 'wxpay' ? self::PRODUCT_CODE_28 : self::PRODUCT_CODE_26;
        $params = $this->basePayload($order) + [
            'bankType' => $payType === 'wxpay' ? '1902000' : '1903000',
            'payMode' => $payMode,
        ];
        if ($payType === 'wxpay') {
            $params['wxAppId'] = $identity['app_id'];
            $params['wxOpenId'] = $identity['identity'];
        } else {
            $params['alipayId'] = $identity['identity'];
        }

        try {
            $data = $this->client()->execute('jsPay', $params);
        } catch (YseqtSdkException $e) {
            throw new PaymentException('银盛e企通JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        if (!in_array((string) ($data['subCode'] ?? ''), ['COM000', 'COM004'], true)) {
            throw new PaymentUncertainException((string) ($data['subMsg'] ?? '银盛 e企通未确认 JSAPI 下单结果'), 40200, ['response' => $data]);
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
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['requestNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['tradeSn'] ?? ''),
        ]);
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
