<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\xsy\XsyClient;
use app\common\sdk\xsy\XsySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use support\Request;
use support\Response;

/**
 * 新生易支付 API 插件。
 *
 * 负责支付宝、微信和银联的扫码、JSAPI、付款码支付，以及查单、关单、退款和异步通知适配。
 */
class XsyApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_REVERSE_SCAN = 'reverseScan';
    private const PRODUCT_ALIPAY = 'ALIPAY';
    private const PRODUCT_WECHAT = 'WECHAT';
    private const PRODUCT_UNIONPAY = 'UNIONPAY';
    private const PRODUCT_WXPAY_MINI = 'wxpay_mini';
    private const PRODUCT_WXPAY_MP = 'wxpay_mp';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';

    private ?XsyClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'xsy_api',
        'name' => '新生易支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.hnapay.com/',
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
            ['type' => 'input', 'field' => 'org_no', 'title' => '机构代码', 'value' => '', 'validate' => [['required' => true, 'message' => '机构代码不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'input', 'field' => 'merchant_no', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'switch', 'field' => 'is_test', 'title' => '测试环境', 'value' => false],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_REVERSE_SCAN => '付款码支付',
                self::PRODUCT_ALIPAY => '支付宝扫码',
                self::PRODUCT_WECHAT => '微信扫码',
                self::PRODUCT_UNIONPAY => '银联扫码',
                self::PRODUCT_ALIPAY_JSAPI => '支付宝生活号',
                self::PRODUCT_WXPAY_MP => '微信公众号',
                self::PRODUCT_WXPAY_MINI => '微信小程序',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'mini_program', 'alipay_oauth']),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '新生易');
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
            self::PRODUCT_WXPAY_MP => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_WXPAY_MINI => $this->directPaymentIdentityRequirement($order, $product, 'mini_program'),
            self::PRODUCT_ALIPAY_JSAPI => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
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
                    'alipay' => self::PRODUCT_REVERSE_SCAN,
                    'wxpay' => self::PRODUCT_REVERSE_SCAN,
                    'bank' => self::PRODUCT_REVERSE_SCAN,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_JSAPI,
                    'wxpay' => self::PRODUCT_WXPAY_MP,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],
            'mini' => [
                'products' => ['wxpay' => self::PRODUCT_WXPAY_MINI],
                'handler' => fn (): array => $this->jsapiPay($order, true),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHAT,
                    'bank' => self::PRODUCT_UNIONPAY,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ];
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准二维码待支付结果
     */
    private function qrcodePay(array $order): array
    {
        $payType = $this->channelPayType((string) $order['pay_type_code']);
        try {
            $data = $this->client()->request('/trade/activeScan', $this->basePayload($order) + [
                'payType' => $payType,
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentException('新生易下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['payUrl'] ?? '');
        if (str_contains($qrcode, 'qrContent=')) {
            $query = parse_url($qrcode, PHP_URL_QUERY) ?: '';
            parse_str($query, $params);
            $qrcode = (string) ($params['qrContent'] ?? $qrcode);
        }
        if ($qrcode === '') {
            throw new PaymentException('新生易未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], $payType, 'activeScan', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 查询订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     *
     * @return array<string, mixed> 标准支付查询结果
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->request('/trade/tradeQuery', [
                'merchantNo' => $this->configText('merchant_no'),
                'orderNo' => (string) $order['pay_no'],
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentUncertainException('新生易查单失败：' . $e->getMessage(), 40200);
        }

        $status = match ((string) ($data['tranSts'] ?? '')) {
            'SUCCESS' => PaymentPluginStatusConstant::SUCCESS,
            'CLOSED' => PaymentPluginStatusConstant::CLOSED,
            'FAIL' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };
        $responsePayNo = trim((string) ($data['orderNo'] ?? ''));

        return [
            'status' => $status,
            'pay_no' => $responsePayNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->integerCents($data['amt'] ?? null, '新生易查单金额')
                : null,
            'chan_order_no' => $responsePayNo,
            'chan_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
            'channel_status' => (string) ($data['tranSts'] ?? ''),
            'message' => (string) ($data['tranSts'] ?? ''),
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
            $data = $this->client()->request('/trade/cancel', [
                'merchantNo' => $this->configText('merchant_no'),
                'orderNo' => (string) $order['pay_no'],
                'payType' => $this->channelPayType((string) $order['pay_type_code']),
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentUncertainException('新生易关单结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => (string) $order['pay_no'],
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '关单成功',
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
            $data = $this->client()->request('/trade/refund', [
                'merchantNo' => $this->configText('merchant_no'),
                'orderNo' => (string) $order['refund_no'],
                'origOrderNo' => (string) $order['pay_no'],
                'amt' => (int) $order['refund_amount'],
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentUncertainException('新生易退款结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) ($data['amt'] ?? $order['refund_amount']),
            'chan_refund_no' => (string) ($data['orderNo'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 验签并解析支付回调。
     *
     * 签名覆盖原始请求体，验签通过后再读取 respData 中的订单、金额和渠道编号。
     *
     * @param Request $request 回调请求
     *
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $raw = $request->rawBody();
        if (!$this->client()->verify($raw)) {
            throw new PaymentException('新生易回调验签失败', 40200);
        }

        $payload = (array) json_decode($raw, true);
        $data = (array) ($payload['respData'] ?? []);

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'pay_no' => trim((string) ($data['orderNo'] ?? '')),
            'paid_amount' => $this->integerCents($data['amt'] ?? null, '新生易回调金额'),
            'message' => (string) ($data['tranSts'] ?? 'SUCCESS'),
            'chan_order_no' => (string) ($data['orderNo'] ?? ''),
            'chan_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
            'channel_status' => (string) ($data['tranSts'] ?? 'SUCCESS'),
        ];
    }

    /**
     * 返回新生易成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '{"code":"success"}';
    }

    /**
     * 返回新生易失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '{"code":"fail"}';
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
     * 根据支付方式补充用户与子应用标识并发起 JSAPI 下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $mini 是否调用已选定的小程序产品
     *
     * @return array<string, mixed> 标准 JSAPI 待支付结果
     */
    private function jsapiPay(array $order, bool $mini = false): array
    {
        $identity = $this->directPaymentIdentityData($order, (string) $order['pay_type_code'] === 'alipay' ? 'alipay_oauth' : ($mini ? 'mini_program' : 'wechat_oauth'));
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('新生易必须先取得当前产品的用户身份', 40200);
        }
        $product = (string) $order['pay_type_code'] === 'alipay' ? self::PRODUCT_ALIPAY_JSAPI : ($mini ? self::PRODUCT_WXPAY_MINI : self::PRODUCT_WXPAY_MP);
        $payType = $this->channelPayType((string) $order['pay_type_code']);
        $payWay = $mini ? '03' : '02';
        try {
            $data = $this->client()->request('/trade/jsapiScan', $this->basePayload($order) + [
                'payType' => $payType,
                'payWay' => $payWay,
                'subAppId' => $identity['app_id'],
                'userId' => $identity['identity'],
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentException('新生易JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $params = (string) $order['pay_type_code'] === 'wxpay'
            ? [
                'appId' => (string) ($data['payAppId'] ?? ''),
                'timeStamp' => (string) ($data['payTimeStamp'] ?? ''),
                'nonceStr' => (string) ($data['paynonceStr'] ?? ''),
                'package' => (string) ($data['payPackage'] ?? ''),
                'signType' => (string) ($data['paySignType'] ?? ''),
                'paySign' => (string) ($data['paySign'] ?? ''),
            ]
            : ['tradeNO' => (string) ($data['source'] ?? '')];

        $payParams = $mini
            ? ['_page' => 'wechatMini', 'request_payment' => $params, 'raw' => $data]
            : $params + ['raw' => $data];

        return $this->payResult($mini ? 'page' : 'jsapi', (string) $order['pay_type_code'], $product, 'jsapiScan', $payParams, $data, $order);
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 标准同步支付结果
     */
    private function scanPay(array $order): array
    {
        try {
            $data = $this->client()->request('/trade/reverseScan', $this->basePayload($order) + [
                'payType' => $this->channelPayType((string) $order['pay_type_code']),
                'authCode' => (string) ($order['extra']['payment']['auth_code'] ?? ''),
            ]);
        } catch (XsySdkException $e) {
            throw new PaymentException('新生易付款码下单失败：' . $e->getMessage(), 40200);
        }

        return $this->successfulPaymentResult($order, [
            'paid_amount' => (int) ($order['amount'] ?? 0),
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => 'reverseScan',
            'pay_action' => 'reverseScan',
            'chan_order_no' => (string) ($data['orderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
        ]);
    }

    /**
     * 构造新生易各支付产品共享的下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     *
     * @return array<string, mixed> 上游下单请求参数
     */
    private function basePayload(array $order): array
    {
        return [
            'merchantNo' => $this->configText('merchant_no'),
            'orderNo' => (string) $order['pay_no'],
            'amt' => (int) $order['amount'],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'trmIp' => (string) $order['client_ip'],
            'customerIp' => (string) $order['client_ip'],
            'notifyUrl' => (string) $order['callback_url'],
        ];
    }

    /**
     * 将标准支付方式映射为新生易支付类型。
     *
     * @param string $payType 标准支付方式代码
     */
    private function channelPayType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNIONPAY',
            default => 'ALIPAY',
        };
    }

    /**
     * 将上游支付凭据包装为标准待支付结果。
     *
     * @param string $page 收银台承接页类型
     * @param string $payType 标准支付方式代码
     * @param string $product 新生易支付类型
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
            'chan_order_no' => (string) ($data['orderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['outOrderNo'] ?? $data['transactionId'] ?? ''),
        ]);
    }

    /**
     * 获取按机构、商户密钥和环境配置初始化的 SDK 客户端。
     */
    private function client(): XsyClient
    {
        if ($this->client === null) {
            $this->client = new XsyClient([
                'org_no' => $this->configText('org_no'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'is_test' => $this->getConfig('is_test', false) ? '1' : '0',
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
