<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\huolian\HuolianClient;
use app\common\sdk\huolian\HuolianSdkException;
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
 * 火脸支付 API 插件。
 *
 * 提供支付宝、微信和银联的扫码、H5、自有微信小程序、支付通知与退款能力。
 * 小程序身份由平台统一中转，拉起参数交给小程序客户端，不提供公众号 JSAPI。
 * 当前适配协议没有可确认的主动查单和关单接口。
 */
class HuolianApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_APPLET = 'applet';
    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WECHAT_H5 = 'wechat_h5';
    private const PRODUCT_ALIPAY = 'alipay';
    private const PRODUCT_WECHAT = 'wechat';
    private const PRODUCT_CLOUD = 'cloud';

    private ?HuolianClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'huolian_api',
        'name' => '火脸支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.lianok.com/',
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
            ['type' => 'input', 'field' => 'auth_code', 'title' => '对接商授权编号', 'value' => '', 'validate' => [['required' => true, 'message' => '授权编号不能为空']]],
            ['type' => 'password', 'field' => 'salt', 'title' => 'MD5加密盐', 'value' => '', 'validate' => [['required' => true, 'message' => 'MD5加密盐不能为空']]],
            ['type' => 'input', 'field' => 'merchant_no', 'title' => '商户ID', 'value' => '', 'validate' => [['required' => true, 'message' => '商户ID不能为空']]],
            ['type' => 'input', 'field' => 'operator_account', 'title' => '收银员手机号', 'value' => '', 'validate' => [['required' => true, 'message' => '收银员手机号不能为空']]],
            ['type' => 'password', 'field' => 'refund_password', 'title' => '退款密码', 'value' => ''],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_APPLET => '自有微信小程序支付',
                self::PRODUCT_ALIPAY_H5 => '支付宝 H5',
                self::PRODUCT_WECHAT_H5 => '微信 H5',
                self::PRODUCT_ALIPAY => '支付宝扫码',
                self::PRODUCT_WECHAT => '微信扫码',
                self::PRODUCT_CLOUD => '银联扫码',
            ]),
            ...$this->directPaymentIdentityConfigFields(['mini_program']),
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
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '火脸支付');
    }

    /**
     * 仅为自有小程序 applet 获取 mini_openid，聚合/H5 页面由机构承接。
     *
     * applet 不注册为公众号 JSAPI；机构托管小程序/支付插件不复用本身份流程。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return $product === self::PRODUCT_APPLET
            ? $this->directPaymentIdentityRequirement($order, $product, 'mini_program')
            : null;
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
            'mini' => [
                'products' => [
                    'wxpay' => self::PRODUCT_APPLET,
                ],
                'handler' => fn (): array => $this->appletPay($order),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WECHAT_H5,
                ],
                'handler' => fn (): array => $this->h5Pay($order),
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WECHAT_H5,
                ],
                'handler' => fn (): array => $this->h5Pay($order),
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WECHAT_H5,
                ],
                'handler' => fn (): array => $this->h5Pay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY,
                    'wxpay' => self::PRODUCT_WECHAT,
                    'bank' => self::PRODUCT_CLOUD,
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
            'wxpay' => 'wechat',
            'bank' => 'cloud',
            default => 'alipay',
        };

        try {
            $data = $this->client()->execute('api.hl.order.pay.unified', $this->basePayload($order) + ['payWay' => $payWay]);
        } catch (HuolianSdkException $e) {
            throw new PaymentException('火脸支付下单失败：' . $e->getMessage(), 40200);
        }

        $payUrl = (string) ($data['payUrl'] ?? '');
        if ($payUrl === '') {
            throw new PaymentException('火脸支付未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $payWay, 'pay.unified', ['qrcode' => $payUrl, 'raw' => $data], $data, $order);
    }

    /**
     * 当前适配协议未提供可确认的主动查单接口。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        throw new UnsupportedPaymentOperationException('火脸支付插件暂不支持主动查单', 40200);
    }

    /**
     * 当前适配协议未提供可确认的关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        throw new UnsupportedPaymentOperationException('火脸支付插件暂不支持关单', 40200);
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
            $data = $this->client()->execute('api.hl.order.refund.operation', [
                'orderNo' => (string) ($order['chan_trade_no'] ?? ''),
                'businessRefundNo' => (string) $order['refund_no'],
                'refundAmount' => FormatHelper::amount((int) $order['refund_amount']),
                'refundPassword' => $this->configText('refund_password'),
                'merchantNo' => $this->configText('merchant_no'),
                'operatorAccount' => $this->configText('operator_account'),
            ]);
        } catch (HuolianSdkException $e) {
            throw new PaymentUncertainException('火脸支付退款结果不确定：' . $e->getMessage(), 40200);
        }

        $refundAmount = isset($data['refundAmount'])
            ? $this->yuanToCents($data['refundAmount'], '火脸支付退款金额')
            : (int) $order['refund_amount'];

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => $refundAmount,
            'chan_refund_no' => (string) ($data['refundNo'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析并验签支付回调。
     *
     * 外层报文验签通过后再解析 respBody，只有 orderStatus=2 才返回支付成功。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $payload = (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('火脸支付回调验签失败', 40200);
        }

        $data = json_decode((string) ($payload['respBody'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        $success = (string) ($data['orderStatus'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($data['businessOrderNo'] ?? '')),
            'paid_amount' => $success ? $this->yuanToCents($data['payAmount'] ?? null, '火脸支付回调金额') : null,
            'message' => (string) ($data['orderStatus'] ?? ''),
            'chan_order_no' => (string) ($data['businessOrderNo'] ?? ''),
            'chan_trade_no' => (string) ($data['orderNo'] ?? ''),
            'channel_status' => (string) ($data['orderStatus'] ?? ''),
        ];
    }

    /**
     * 返回火脸成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回火脸失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'FAIL';
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
     * H5 预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function h5Pay(array $order): array
    {
        $payType = (string) $order['pay_type_code'];
        $payWay = $payType === 'wxpay' ? 'wechat' : 'alipay';
        try {
            $data = $this->client()->execute('api.hl.order.pay.h5', $this->basePayload($order) + [
                'payWay' => $payWay,
                'pageNotifyUrl' => (string) $order['return_url'],
            ]);
        } catch (HuolianSdkException $e) {
            throw new PaymentException('火脸H5下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('jump', $payType, $payWay . '_h5', 'pay.h5', ['url' => (string) ($data['payUrl'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 使用当前小程序 AppID 与 mini_openid 预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function appletPay(array $order): array
    {
        $identity = $this->directPaymentIdentityData($order, 'mini_program');
        if ($identity['identity'] === '') {
            throw new PaymentDefinitiveException('火脸小程序支付缺少 mini_openid，请先完成身份授权', 40200);
        }
        try {
            $data = $this->client()->execute('api.hl.order.pay.applet', $this->basePayload($order) + [
                'payWay' => 'wechat',
                'appId' => $identity['app_id'],
                'openId' => $identity['identity'],
            ]);
        } catch (HuolianSdkException $e) {
            throw new PaymentException('火脸小程序下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['jsPayInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('page', 'wxpay', self::PRODUCT_APPLET, 'pay.applet', [
            '_page' => 'wechatMini',
            'request_payment' => (array) $payInfo,
            'raw' => $data,
        ], $data, $order);
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
            'businessOrderNo' => (string) $order['pay_no'],
            'payAmount' => FormatHelper::amount((int) $order['amount']),
            'merchantNo' => $this->configText('merchant_no'),
            'operatorAccount' => $this->configText('operator_account'),
            'notifyUrl' => (string) $order['callback_url'],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'clientIp' => (string) $order['client_ip'],
        ];
    }

    /**
     * 包装标准支付结果。
     *
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 火脸产品编码
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
            'chan_order_no' => (string) ($data['businessOrderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['orderNo'] ?? ''),
        ]);
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return HuolianClient
     */
    private function client(): HuolianClient
    {
        if ($this->client === null) {
            $this->client = new HuolianClient([
                'auth_code' => $this->configText('auth_code'),
                'salt' => $this->configText('salt'),
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
