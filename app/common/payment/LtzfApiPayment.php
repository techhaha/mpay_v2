<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\ltzf\LtzfClient;
use app\common\sdk\ltzf\LtzfSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 蓝兔支付 API 插件。
 */
class LtzfApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_WXPAY_JSAPI_CONVENIENT = 'wxpay_jsapi_convenient';
    private const PRODUCT_WXPAY_H5 = 'wxpay_h5';
    private const PRODUCT_ALIPAY_H5 = 'alipay_h5';
    private const PRODUCT_WXPAY_NATIVE = 'wxpay_native';
    private const PRODUCT_ALIPAY_NATIVE = 'alipay_native';

    private ?LtzfClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'ltzf_api',
        'name' => '蓝兔支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.ltzf.cn/',
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
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'password', 'field' => 'key', 'title' => '商户密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户密钥不能为空']]],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_WXPAY_JSAPI_CONVENIENT => '微信 JSAPI 便捷支付',
                self::PRODUCT_WXPAY_H5 => '微信 H5',
                self::PRODUCT_ALIPAY_H5 => '支付宝 H5',
                self::PRODUCT_WXPAY_NATIVE => '微信扫码',
                self::PRODUCT_ALIPAY_NATIVE => '支付宝扫码',
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
                    'wxpay' => self::PRODUCT_WXPAY_JSAPI_CONVENIENT,
                ],
                'handler' => fn (): array => $payType === 'wxpay'
                ? $this->productPay($order, '/api/wxpay/jsapi_convenient')
                : throw new PaymentException('蓝兔支付当前支付方式不支持JSAPI产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']),
            ],
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WXPAY_H5,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType === 'wxpay' ? '/api/wxpay/jump_h5' : '/api/alipay/h5'),
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_H5,
                    'wxpay' => self::PRODUCT_WXPAY_H5,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType === 'wxpay' ? '/api/wxpay/jump_h5' : '/api/alipay/h5'),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_ALIPAY_NATIVE,
                    'wxpay' => self::PRODUCT_WXPAY_NATIVE,
                ],
                'handler' => fn (): array => $this->productPay($order, $payType === 'wxpay' ? '/api/wxpay/native' : '/api/alipay/native'),
            ],
        ], '蓝兔支付');
    }

    /**
     * 按蓝兔接口路径下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $path 接口路径
     * @return array<string, mixed>
     */
    private function productPay(array $order, string $path): array
    {
        $payType = (string) $order['pay_type_code'];
        try {
            $data = $this->client()->post($path, $this->basePayload($order), ['mch_id', 'out_trade_no', 'total_fee', 'body', 'timestamp', 'notify_url']);
        } catch (LtzfSdkException $e) {
            throw new PaymentException('蓝兔支付下单失败：' . $e->getMessage(), 40200);
        }

        if ($path === '/api/alipay/h5') {
            return $this->payResult('jump', $payType, 'alipay_h5', $path, ['url' => (string) ($data['h5_url'] ?? ''), 'raw' => $data], $data, $order);
        }
        if ($path === '/api/wxpay/jump_h5') {
            return $this->payResult('jump', $payType, 'wxpay_h5', $path, ['url' => is_string($data) ? $data : (string) ($data['h5_url'] ?? '')], (array) $data, $order);
        }
        if ($path === '/api/wxpay/jsapi_convenient') {
            return $this->payResult('jump', $payType, 'wxpay_jsapi_convenient', $path, ['url' => (string) ($data['order_url'] ?? ''), 'raw' => $data], $data, $order);
        }

        $qrcode = $payType === 'wxpay' ? (string) ($data['code_url'] ?? '') : (string) $data;
        if ($qrcode === '') {
            throw new PaymentException('蓝兔支付未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $payType === 'wxpay' ? 'wxpay_native' : 'alipay_native', $path, ['qrcode' => $qrcode, 'raw' => $data], (array) $data, $order);
    }

    /**
     * 蓝兔旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '蓝兔支付插件暂不支持主动查单'];
    }

    /**
     * 蓝兔旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '蓝兔支付插件暂不支持关单'];
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $path = (string) ($order['pay_type_code'] ?? '') === 'wxpay' ? '/api/wxpay/refund_order' : '/api/alipay/refund_order';
        try {
            $data = $this->client()->post($path, [
                'out_trade_no' => (string) $order['pay_no'],
                'out_refund_no' => (string) $order['refund_no'],
                'refund_fee' => FormatHelper::amount((int) $order['refund_amount']),
            ], ['mch_id', 'out_trade_no', 'out_refund_no', 'timestamp', 'refund_fee']);
        } catch (LtzfSdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['out_trade_no'] ?? $order['refund_no']),
            'refund_amount' => (int) $order['refund_amount'],
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
        if (!$this->client()->verify($payload, ['code', 'timestamp', 'mch_id', 'order_no', 'out_trade_no', 'pay_no', 'total_fee'])) {
            throw new PaymentException('蓝兔支付回调验签失败', 40200);
        }

        $success = (string) ($payload['code'] ?? '') === '0';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['msg'] ?? $payload['code'] ?? ''),
            'channel_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'channel_trade_no' => (string) ($payload['order_no'] ?? $payload['pay_no'] ?? ''),
            'channel_status' => (string) ($payload['code'] ?? ''),
        ];
    }

    /**
     * 返回蓝兔成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'SUCCESS';
    }

    /**
     * 返回蓝兔失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'FAIL';
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
            'out_trade_no' => (string) $order['pay_no'],
            'total_fee' => FormatHelper::amount((int) $order['amount']),
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) $order['return_url'],
            'quit_url' => (string) $order['return_url'],
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
            'chan_trade_no' => (string) ($data['order_no'] ?? $data['pay_no'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): LtzfClient
    {
        if ($this->client === null) {
            $this->client = new LtzfClient([
                'mch_id' => $this->configText('mch_id'),
                'key' => $this->configText('key'),
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
