<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\hlpay\HlpayClient;
use app\common\sdk\hlpay\HlpaySdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 汇联支付 API 插件。
 */
class HlpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?HlpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'hlpay_api',
        'name' => '汇联支付API',
        'author' => 'MPAY',
        'link' => 'https://www.huilianlink.com/',
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
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'input', 'field' => 'sub_sn', 'title' => '子商户编码', 'value' => ''],
            ['type' => 'input', 'field' => 'channel_code', 'title' => '通道编码', 'value' => ''],
            ['type' => 'select', 'field' => 'scene_type', 'title' => '场景类型', 'value' => '1', 'options' => [['label' => '线下', 'value' => '1'], ['label' => '线上', 'value' => '2']]],
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
        $method = (string) ($order['extra']['payment']['method'] ?? '');
        if ($method === 'jsapi') {
            return $this->jsapiPay($order);
        }

        $payType = (string) $order['pay_type_code'];
        $product = match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNION_PAY',
            default => 'ALIPAY',
        };

        try {
            $data = $this->client()->execute('/openapi/pay/create', $this->basePayload($order) + [
                'payType' => $product,
                'paySubType' => 'NATIVE',
            ]);
        } catch (HlpaySdkException $e) {
            throw new PaymentException('汇联支付下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['payInfo'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('汇联支付未返回支付链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $product . '_NATIVE', 'pay/create', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 汇联旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '汇联支付插件暂不支持主动查单'];
    }

    /**
     * 汇联旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '汇联支付插件暂不支持关单'];
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
            $data = $this->client()->execute('/openapi/pay/refund', [
                'payOrderNo' => (string) ($order['chan_trade_no'] ?? ''),
                'mchRefundOrderNo' => (string) $order['refund_no'],
                'amount' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (HlpaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['instOrderNo'] ?? $order['refund_no']),
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
            throw new PaymentException('汇联支付回调验签失败', 40200);
        }

        $data = (array) ($payload['data'] ?? []);
        $success = (string) ($data['state'] ?? '') === '3';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($data['state'] ?? ''),
            'channel_order_no' => (string) ($data['mchOrderNo'] ?? ''),
            'channel_trade_no' => (string) ($data['payOrderNo'] ?? ''),
            'channel_status' => (string) ($data['state'] ?? ''),
        ];
    }

    /**
     * 返回汇联成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回汇联失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'sign fail';
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
        $product = $payType === 'wxpay' ? 'WECHAT' : ($payType === 'bank' ? 'UNION_PAY' : 'ALIPAY');
        $extra = ['userId' => (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '')];
        if ((string) ($payment['sub_appid'] ?? '') !== '') {
            $extra['subAppid'] = (string) $payment['sub_appid'];
        }

        try {
            $data = $this->client()->execute('/openapi/pay/create', $this->basePayload($order) + [
                'payType' => $product,
                'paySubType' => 'JSAPI',
                'extra' => $extra,
            ]);
        } catch (HlpaySdkException $e) {
            throw new PaymentException('汇联支付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', $payType, $product . '_JSAPI', 'pay/create', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        $payload = [
            'sceneType' => $this->configText('scene_type') ?: '1',
            'mchOrderNo' => (string) $order['pay_no'],
            'amount' => FormatHelper::amount((int) $order['amount']),
            'clientIp' => (string) $order['client_ip'],
            'subject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notifyUrl' => (string) $order['callback_url'],
            'redirectUrl' => (string) $order['return_url'],
        ];
        if ($this->configText('channel_code') !== '') {
            $payload['channelCode'] = $this->configText('channel_code');
        }

        return $payload;
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
            'chan_order_no' => (string) ($data['mchOrderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['payOrderNo'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): HlpayClient
    {
        if ($this->client === null) {
            $this->client = new HlpayClient([
                'app_id' => $this->configText('app_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'sub_sn' => $this->configText('sub_sn'),
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
