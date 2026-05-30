<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\dinpay\DinpayClient;
use app\common\sdk\dinpay\DinpaySdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 智付支付 API 插件。
 */
class DinpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?DinpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'dinpay_api',
        'name' => '智付支付API',
        'author' => 'MPAY',
        'link' => 'https://www.dinpay.com/',
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
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'input', 'field' => 'sub_mch_id', 'title' => '子商户号', 'value' => ''],
            ['type' => 'input', 'field' => 'report_id', 'title' => '渠道商户报备ID', 'value' => ''],
            ['type' => 'switch', 'field' => 'is_test', 'title' => '测试环境', 'value' => false],
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
        if ($method === 'h5') {
            return $this->h5Pay($order);
        }
        if ($method === 'jsapi') {
            return $this->jsapiPay($order);
        }

        $payType = $this->channelPayType((string) $order['pay_type_code']);
        try {
            $data = $this->client()->execute('/api/appPay/pay', $this->basePayload($order) + [
                'interfaceName' => 'AppPay',
                'paymentType' => $payType,
                'paymentMethods' => 'SCAN',
                'paymentCode' => '1',
            ]);
        } catch (DinpaySdkException $e) {
            throw new PaymentException('智付下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], 'SCAN', 'appPay/pay', ['qrcode' => (string) ($data['qrcode'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 智付旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '智付插件暂不支持主动查单'];
    }

    /**
     * 智付旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '智付插件暂不支持关单'];
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
            $data = $this->client()->execute('/api/appPay/payRefund', [
                'interfaceName' => 'AppPayRefund',
                'payOrderNo' => (string) $order['pay_no'],
                'refundOrderNo' => (string) $order['refund_no'],
                'refundAmount' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (DinpaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['refundChannelNumber'] ?? $order['refund_no']),
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
        $payload = $request->post();
        if (!$this->client()->verify((string) ($payload['data'] ?? ''), (string) ($payload['sign'] ?? ''))) {
            throw new PaymentException('智付回调验签失败', 40200);
        }

        $data = (array) json_decode((string) ($payload['data'] ?? ''), true);
        $success = (string) ($data['orderStatus'] ?? '') === 'SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($data['orderStatus'] ?? ''),
            'channel_order_no' => (string) ($data['orderNo'] ?? ''),
            'channel_trade_no' => (string) ($data['channelNumber'] ?? ''),
            'channel_status' => (string) ($data['orderStatus'] ?? ''),
        ];
    }

    /**
     * 返回智付成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回智付失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * H5 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function h5Pay(array $order): array
    {
        try {
            $data = $this->client()->execute('/api/appPay/payH5', $this->basePayload($order) + [
                'interfaceName' => 'AppPayH5WFT',
                'paymentType' => $this->channelPayType((string) $order['pay_type_code']),
                'paymentMethods' => 'WAP',
                'applyName' => 'MPAY',
                'applyType' => 'AND_WAP',
                'applyId' => (string) $order['return_url'],
                'isNative' => '0',
                'successToUrl' => (string) $order['return_url'],
            ]);
        } catch (DinpaySdkException $e) {
            throw new PaymentException('智付H5下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('jump', (string) $order['pay_type_code'], 'WAP', 'appPay/payH5', ['url' => (string) ($data['payInfo'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        try {
            $data = $this->client()->execute('/api/appPay/payPublic', $this->basePayload($order) + [
                'interfaceName' => 'AppPayPublic',
                'paymentType' => $this->channelPayType((string) $order['pay_type_code']),
                'paymentMethods' => 'PUBLIC',
                'appid' => (string) ($payment['sub_appid'] ?? '1'),
                'openid' => (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? ''),
                'isNative' => '1',
                'successToUrl' => (string) $order['return_url'],
            ]);
        } catch (DinpaySdkException $e) {
            throw new PaymentException('智付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = json_decode((string) ($data['payInfo'] ?? ''), true);
        $payInfo = is_array($payInfo) ? $payInfo : ['tradeNO' => (string) ($data['payInfo'] ?? '')];

        return $this->payResult('jsapi', (string) $order['pay_type_code'], 'PUBLIC', 'appPay/payPublic', $payInfo + ['raw' => $data], $data, $order);
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
            'payAmount' => FormatHelper::amount((int) $order['amount']),
            'currency' => 'CNY',
            'orderNo' => (string) $order['pay_no'],
            'orderIp' => (string) $order['client_ip'],
            'goodsName' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notifyUrl' => (string) $order['callback_url'],
        ];
        if ($this->configText('report_id') !== '') {
            $payload['reportId'] = $this->configText('report_id');
        }

        return $payload;
    }

    /**
     * 支付方式映射。
     */
    private function channelPayType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WXPAY',
            'bank' => 'UNIONPAY',
            default => 'ALIPAY',
        };
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
            'chan_order_no' => (string) ($data['orderNo'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['channelNumber'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): DinpayClient
    {
        if ($this->client === null) {
            $this->client = new DinpayClient([
                'mch_id' => $this->configText('sub_mch_id') ?: $this->configText('mch_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'is_test' => $this->getConfig('is_test', false) ? '1' : '0',
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
