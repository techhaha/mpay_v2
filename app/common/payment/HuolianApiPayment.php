<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\huolian\HuolianClient;
use app\common\sdk\huolian\HuolianSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 火脸支付 API 插件。
 */
class HuolianApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

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
                self::PRODUCT_APPLET => '微信小程序/JSAPI',
                self::PRODUCT_ALIPAY_H5 => '支付宝 H5',
                self::PRODUCT_WECHAT_H5 => '微信 H5',
                self::PRODUCT_ALIPAY => '支付宝扫码',
                self::PRODUCT_WECHAT => '微信扫码',
                self::PRODUCT_CLOUD => '银联扫码',
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
        ], '火脸支付');
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
     * 火脸旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '火脸支付插件暂不支持主动查单'];
    }

    /**
     * 火脸旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '火脸支付插件暂不支持关单'];
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
            $data = $this->client()->execute('api.hl.order.refund.operation', [
                'orderNo' => (string) ($order['chan_trade_no'] ?? ''),
                'businessRefundNo' => (string) $order['refund_no'],
                'refundAmount' => FormatHelper::amount((int) $order['refund_amount']),
                'refundPassword' => $this->configText('refund_password'),
                'merchantNo' => $this->configText('merchant_no'),
                'operatorAccount' => $this->configText('operator_account'),
            ]);
        } catch (HuolianSdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refundNo'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['refundAmount'] ?? 0)) * 100),
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
        $payload = (array) json_decode($request->rawBody(), true);
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('火脸支付回调验签失败', 40200);
        }

        $data = json_decode((string) ($payload['respBody'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        $success = (string) ($data['orderStatus'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($data['orderStatus'] ?? ''),
            'channel_order_no' => (string) ($data['businessOrderNo'] ?? ''),
            'channel_trade_no' => (string) ($data['orderNo'] ?? ''),
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
     * H5 预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
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
     * 小程序/JSAPI 预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function appletPay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        try {
            $data = $this->client()->execute('api.hl.order.pay.applet', $this->basePayload($order) + [
                'payWay' => 'wechat',
                'appId' => (string) ($payment['sub_appid'] ?? ''),
                'openId' => (string) ($payment['mini_openid'] ?? $payment['sub_openid'] ?? ''),
            ]);
        } catch (HuolianSdkException $e) {
            throw new PaymentException('火脸小程序下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['jsPayInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', 'wxpay', 'applet', 'pay.applet', ((array) $payInfo) + ['raw' => $data], $data, $order);
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
            'chan_order_no' => (string) ($data['businessOrderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['orderNo'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
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
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
