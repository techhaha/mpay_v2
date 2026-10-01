<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\leshua\LeshuaClient;
use app\common\sdk\leshua\LeshuaSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use support\Request;
use support\Response;

/**
 * 乐刷聚合支付 API 插件。
 *
 * 提供支付宝、微信和银联的扫码、支付宝/微信 JSAPI、付款码、支付通知与退款能力。
 * 当前适配协议没有可确认的主动查单和关单接口。
 */
class LeshuaApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_UPLOAD_AUTHCODE = 'upload_authcode';
    private const PRODUCT_ZFBZF_JSAPI = 'ZFBZF_JSAPI';
    private const PRODUCT_WXZF_JSAPI = 'WXZF_JSAPI';
    private const PRODUCT_ZFBZF = 'ZFBZF';
    private const PRODUCT_WXZF = 'WXZF';
    private const PRODUCT_UPSMZF = 'UPSMZF';
    private const PRODUCT_WXZF_MINI = 'WXZF_MINI';

    private ?LeshuaClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'leshua_api',
        'name' => '乐刷聚合支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'http://www.leshuazf.com/',
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
            ['type' => 'input', 'field' => 'merchant_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'password', 'field' => 'trade_key', 'title' => '交易密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '交易密钥不能为空']]],
            ['type' => 'password', 'field' => 'notify_key', 'title' => '异步通知密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '异步通知密钥不能为空']]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_UPLOAD_AUTHCODE => '付款码支付',
                self::PRODUCT_ZFBZF_JSAPI => '支付宝 JSAPI',
                self::PRODUCT_WXZF_JSAPI => '微信 JSAPI',
                self::PRODUCT_WXZF_MINI => '微信小程序',
                self::PRODUCT_ZFBZF => '支付宝扫码',
                self::PRODUCT_WXZF => '微信扫码',
                self::PRODUCT_UPSMZF => '银联扫码',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'mini_program', 'alipay_oauth']),
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
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '乐刷');
    }

    /**
     * 按与下单一致的产品声明预检身份；公众号、小程序和支付宝 UID 不互相替代。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 当前产品的身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_WXZF_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_WXZF_MINI => $this->directPaymentIdentityRequirement($order, $product, 'mini_program'),
            self::PRODUCT_ZFBZF_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
            default => null,
        };
    }

    /**
     * 声明已实现的产品；选择器在任何请求前过滤未开通产品及不合理环境。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, array<string, mixed>> 产品与处理器声明
     */
    private function paymentProducts(array $order): array
    {
        return [

            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_UPLOAD_AUTHCODE,
                    'wxpay' => self::PRODUCT_UPLOAD_AUTHCODE,
                    'bank' => self::PRODUCT_UPLOAD_AUTHCODE,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ZFBZF_JSAPI,
                    'wxpay' => self::PRODUCT_WXZF_JSAPI,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'mini' => [
                'products' => ['wxpay' => self::PRODUCT_WXZF_MINI],
                'handler' => fn (): array => $this->jsapiPay($order, true),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ZFBZF,
                    'wxpay' => self::PRODUCT_WXZF,
                    'bank' => self::PRODUCT_UPSMZF,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ];
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $payWay = match ($payType) {
            'wxpay' => 'WXZF',
            'bank' => 'UPSMZF',
            default => 'ZFBZF',
        };
        $jspayFlag = $payType === 'wxpay' ? '2' : '0';

        try {
            $data = $this->client()->request($this->basePayload($order) + [
                'service' => 'get_tdcode',
                'jspay_flag' => $jspayFlag,
                'pay_way' => $payWay,
            ]);
        } catch (LeshuaSdkException $e) {
            throw new PaymentException('乐刷下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data[$payType === 'wxpay' ? 'jspay_url' : 'td_code'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('乐刷未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $payWay, 'get_tdcode', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('乐刷插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('乐刷插件暂不支持关单', 40200);
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
            $data = $this->client()->request([
                'service' => 'unified_refund',
                'leshua_order_id' => (string) ($order['chan_trade_no'] ?? ''),
                'merchant_refund_id' => (string) $order['refund_no'],
                'refund_amount' => (string) (int) $order['refund_amount'],
            ]);
        } catch (LeshuaSdkException $e) {
            throw new PaymentUncertainException('乐刷退款结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) ($data['refund_amount'] ?? $order['refund_amount']),
            'chan_refund_no' => (string) ($data['leshua_refund_id'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析并验签 XML 支付回调。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = $this->client()->fromXml($request->rawBody());
        if (!$this->client()->verifyNotify($payload)) {
            throw new PaymentException('乐刷回调验签失败', 40200);
        }

        $success = (string) ($payload['status'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($payload['third_order_id'] ?? '')),
            'paid_amount' => $success ? $this->integerCents($payload['amount'] ?? null, '乐刷回调金额') : null,
            'message' => (string) ($payload['status'] ?? ''),
            'chan_order_no' => (string) ($payload['third_order_id'] ?? ''),
            'chan_trade_no' => (string) ($payload['leshua_order_id'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回乐刷成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '000000';
    }

    /**
     * 返回乐刷失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 读取渠道整数分金额。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
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
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否调用已选定的小程序产品
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order, bool $mini = false): array
    {
        $payType = (string) $order['pay_type_code'];
        $identity = $this->directPaymentIdentityData($order, $payType === 'alipay' ? 'alipay_oauth' : ($mini ? 'mini_program' : 'wechat_oauth'));
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('乐刷必须先取得当前产品的用户身份', 40200);
        }
        $product = $payType === 'alipay' ? self::PRODUCT_ZFBZF_JSAPI : ($mini ? self::PRODUCT_WXZF_MINI : self::PRODUCT_WXZF_JSAPI);
        $payWay = $payType === 'wxpay' ? 'WXZF' : 'ZFBZF';
        $payload = $this->basePayload($order) + [
            'service' => 'get_tdcode',
            // 1 为公众号/生活号，3 为小程序，不按传入了哪个 openid 猜产品。
            'jspay_flag' => $mini ? '3' : '1',
            'pay_way' => $payWay,
            'sub_openid' => $identity['identity'],
        ];
        if ($payType === 'wxpay') {
            $payload['appid'] = $identity['app_id'];
        }

        try {
            $data = $this->client()->request($payload);
        } catch (LeshuaSdkException $e) {
            throw new PaymentException('乐刷JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['jspay_info'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        $params = $mini
            ? ['_page' => 'wechatMini', 'request_payment' => (array) $payInfo, 'raw' => $data]
            : ((array) $payInfo) + ['raw' => $data];

        return $this->payResult($mini ? 'page' : 'jsapi', $payType, $product, 'get_tdcode', $params, $data, $order);
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付成功结果
     */
    private function scanPay(array $order): array
    {
        try {
            $data = $this->client()->request($this->basePayload($order) + [
                'service' => 'upload_authcode',
                'auth_code' => (string) ($order['extra']['payment']['auth_code'] ?? ''),
            ]);
        } catch (LeshuaSdkException $e) {
            throw new PaymentException('乐刷付款码下单失败：' . $e->getMessage(), 40200);
        }

        return $this->successfulPaymentResult($order, [
            'paid_amount' => (int) ($order['amount'] ?? 0),
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => 'upload_authcode',
            'pay_action' => 'upload_authcode',
            'chan_order_no' => (string) ($data['third_order_id'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['leshua_order_id'] ?? ''),
        ]);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 渠道下单参数
     */
    private function basePayload(array $order): array
    {
        return [
            'third_order_id' => (string) $order['pay_no'],
            'amount' => (string) (int) $order['amount'],
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
            'client_ip' => (string) $order['client_ip'],
        ];
    }

    /**
     * 包装标准支付结果。
     *
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 乐刷产品编码
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
            'chan_order_no' => (string) ($data['third_order_id'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['leshua_order_id'] ?? ''),
        ]);
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return LeshuaClient
     */
    private function client(): LeshuaClient
    {
        if ($this->client === null) {
            $this->client = new LeshuaClient([
                'merchant_id' => $this->configText('merchant_id'),
                'trade_key' => $this->configText('trade_key'),
                'notify_key' => $this->configText('notify_key'),
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
