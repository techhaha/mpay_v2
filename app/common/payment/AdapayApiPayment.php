<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\adapay\AdapayClient;
use app\common\sdk\adapay\AdapaySdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * AdaPay 支付 API 插件。
 */
class AdapayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?AdapayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'adapay_api',
        'name' => 'AdaPay支付API',
        'author' => 'MPAY',
        'link' => 'https://www.adapay.tech/',
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
            ['type' => 'input', 'field' => 'app_id', 'title' => '应用AppID', 'value' => '', 'validate' => [['required' => true, 'message' => '应用AppID不能为空']]],
            ['type' => 'password', 'field' => 'api_key', 'title' => 'API Key', 'value' => '', 'validate' => [['required' => true, 'message' => 'API Key不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户RSA私钥', 'value' => '', 'props' => ['rows' => 5], 'validate' => [['required' => true, 'message' => '商户RSA私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => 'AdaPay平台公钥', 'value' => '', 'props' => ['rows' => 4], 'validate' => [['required' => true, 'message' => 'AdaPay平台公钥不能为空']]],
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
        $method = (string) ($order['extra']['payment']['method'] ?? '');
        if ($method === 'jsapi') {
            return $this->jsapiPay($order);
        }
        if ($payType === 'wxpay') {
            return $this->wxScanPay($order);
        }

        $channel = $payType === 'bank' ? 'union_qr' : 'alipay_qr';
        try {
            $data = $this->client()->createPayment($this->basePayload($order) + ['pay_channel' => $channel]);
        } catch (AdapaySdkException $e) {
            throw new PaymentException('AdaPay下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['expend']['qrcode_url'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('AdaPay未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $channel, 'payments', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        $paymentId = (string) ($order['chan_trade_no'] ?? '');
        if ($paymentId === '') {
            return ['success' => false, 'msg' => 'AdaPay支付对象ID为空'];
        }

        try {
            $data = $this->client()->queryPayment($paymentId);
        } catch (AdapaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        $status = match ((string) ($data['status'] ?? '')) {
            'succeeded' => PaymentPluginStatusConstant::SUCCESS,
            'failed' => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['order_no'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['id'] ?? $paymentId),
            'channel_status' => (string) ($data['status'] ?? ''),
            'message' => (string) ($data['status'] ?? ''),
            'raw_data' => $data,
        ];
    }

    /**
     * AdaPay 旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => 'AdaPay插件暂不支持关单'];
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
            $data = $this->client()->refund((string) ($order['chan_trade_no'] ?? ''), [
                'refund_order_no' => (string) $order['refund_no'],
                'refund_amt' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (AdapaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refund_order_no'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['refund_amt'] ?? 0)) * 100),
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
        $sign = (string) ($request->post('sign') ?? '');
        $dataText = (string) ($request->post('data') ?? '');
        if (!$this->client()->verifyNotify($sign, $dataText)) {
            throw new PaymentException('AdaPay回调验签失败', 40200);
        }

        $payload = (array) json_decode($dataText, true);
        $success = (string) ($payload['status'] ?? '') === 'succeeded';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['status'] ?? ''),
            'channel_order_no' => (string) ($payload['order_no'] ?? ''),
            'channel_trade_no' => (string) ($payload['id'] ?? $payload['party_order_id'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回 AdaPay 成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'Ok';
    }

    /**
     * 返回 AdaPay 失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'No';
    }

    /**
     * 微信扫码使用 AdaPay 页面预下单返回二维码。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function wxScanPay(array $order): array
    {
        try {
            $data = $this->client()->pageRequest('qrPrePay.qrPreOrder', [
                'order_no' => (string) $order['pay_no'],
                'pay_channel' => 'wx_lite',
                'pay_amt' => FormatHelper::amount((int) $order['amount']),
                'goods_title' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'currency' => 'cny',
                'notify_url' => (string) $order['callback_url'],
            ]);
        } catch (AdapaySdkException $e) {
            throw new PaymentException('AdaPay微信扫码下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['expend']['qr_pay_url'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('AdaPay未返回微信二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', 'wxpay', 'wx_lite', 'qrPrePay.qrPreOrder', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
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
        $channel = $payType === 'wxpay' ? 'wx_pub' : 'alipay_pub';
        $expend = $payType === 'wxpay'
            ? ['openid' => (string) ($payment['sub_openid'] ?? '')]
            : ['buyer_id' => (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '')];

        try {
            $data = $this->client()->createPayment($this->basePayload($order) + ['pay_channel' => $channel, 'expend' => $expend]);
        } catch (AdapaySdkException $e) {
            throw new PaymentException('AdaPayJSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['expend']['pay_info'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }
        $payInfo = (array) $payInfo;
        $payInfo['raw'] = $data;

        return $this->payResult('jsapi', $payType, $channel, 'payments', $payInfo, $data, $order);
    }

    /**
     * 构造 AdaPay 支付公共参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        return [
            'order_no' => (string) $order['pay_no'],
            'pay_amt' => FormatHelper::amount((int) $order['amount']),
            'goods_title' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'goods_desc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'currency' => 'cny',
            'notify_url' => (string) $order['callback_url'],
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
            'chan_order_no' => (string) ($data['order_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['id'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): AdapayClient
    {
        if ($this->client === null) {
            $this->client = new AdapayClient([
                'app_id' => $this->configText('app_id'),
                'api_key' => $this->configText('api_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
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
