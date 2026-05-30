<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\hnapay\HnapayClient;
use app\common\sdk\hnapay\HnapaySdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 新生支付 API 插件。
 */
class HnapayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?HnapayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'hnapay_api',
        'name' => '新生支付API',
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
            ['type' => 'input', 'field' => 'mer_id', 'title' => '商户ID', 'value' => '', 'validate' => [['required' => true, 'message' => '商户ID不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '新生公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '新生公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'input', 'field' => 'merchant_id', 'title' => '报备编号', 'value' => ''],
            ['type' => 'select', 'field' => 'interface_type', 'title' => '接口类型', 'value' => 'scan', 'options' => [['label' => '扫码支付', 'value' => 'scan'], ['label' => '公众号/生活号支付', 'value' => 'jsapi'], ['label' => '支付宝H5', 'value' => 'h5']]],
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
        $method = (string) ($order['extra']['payment']['method'] ?? $this->configText('interface_type'));
        if ($method === 'h5' && (string) $order['pay_type_code'] === 'alipay') {
            $html = $this->client()->h5Html((string) $order['pay_no'], [
                'tranAmt' => FormatHelper::amount((int) $order['amount']),
                'payType' => 'HnaZFB',
                'frontUrl' => (string) $order['return_url'],
                'notifyUrl' => (string) $order['callback_url'],
                'orderSubject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'merchantId' => json_encode(['02' => $this->configText('merchant_id')], JSON_UNESCAPED_UNICODE),
                'merUserIp' => (string) $order['client_ip'],
            ]);

            return $this->payResult('html', 'alipay', 'h5', 'multipay/h5', ['html' => $html], [], $order);
        }
        if ($method === 'jsapi') {
            return $this->jsapiPay($order);
        }

        $orgCode = $this->orgCode((string) $order['pay_type_code']);
        try {
            $data = $this->client()->scanPay([
                'merOrderNum' => (string) $order['pay_no'],
                'tranAmt' => (string) (int) $order['amount'],
                'submitTime' => substr((string) $order['pay_no'], 3, 14) ?: date('YmdHis'),
                'orgCode' => $orgCode,
                'goodsName' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'tranIP' => (string) $order['client_ip'],
                'notifyUrl' => (string) $order['callback_url'],
                'weChatMchId' => $this->configText('merchant_id'),
            ]);
        } catch (HnapaySdkException $e) {
            throw new PaymentException('新生支付下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], $orgCode, 'scanPay', ['qrcode' => (string) ($data['qrCodeUrl'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 新生旧插件未提供统一主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '新生支付插件暂不支持主动查单'];
    }

    /**
     * 新生旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '新生支付插件暂不支持关单'];
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
            $data = $this->client()->refund((string) $order['pay_no'], [
                'orgHnapayOrderId' => (string) ($order['chan_trade_no'] ?? ''),
                'refundAmt' => FormatHelper::amount((int) $order['refund_amount']),
                'notifyServerUrl' => (string) ($order['callback_url'] ?? ''),
            ]);
        } catch (HnapaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['hnapayOrderId'] ?? $order['refund_no']),
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
        $isScan = isset($payload['merOrderNum']);
        $verified = $isScan ? $this->client()->verifyScanNotify($payload) : $this->client()->verifyPayNotify($payload);
        if (!$verified) {
            throw new PaymentException('新生支付回调验签失败', 40200);
        }

        $success = (string) ($payload[$isScan ? 'respCode' : 'resultCode'] ?? '') === '0000';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload[$isScan ? 'respCode' : 'resultCode'] ?? ''),
            'channel_order_no' => (string) ($payload[$isScan ? 'merOrderNum' : 'merOrderId'] ?? ''),
            'channel_trade_no' => (string) ($payload['hnapayOrderId'] ?? ''),
            'channel_status' => (string) ($payload[$isScan ? 'respCode' : 'resultCode'] ?? ''),
        ];
    }

    /**
     * 返回新生成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '200';
    }

    /**
     * 返回新生失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'sign_error';
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
        $orgCode = $this->orgCode((string) $order['pay_type_code']);
        $payload = [
            'tranAmt' => FormatHelper::amount((int) $order['amount']),
            'orgCode' => $orgCode,
            'notifyServerUrl' => (string) $order['callback_url'],
            'merUserIp' => (string) $order['client_ip'],
            'goodsInfo' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'orderSubject' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'merchantId' => $this->configText('merchant_id'),
        ];
        if ($orgCode === 'WECHATPAY') {
            $payload['appId'] = (string) ($payment['sub_appid'] ?? '');
            $payload['openId'] = (string) ($payment['sub_openid'] ?? '');
        } else {
            $payload['aliAppId'] = (string) ($payment['sub_appid'] ?? '');
            $payload['buyerId'] = (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '');
        }

        try {
            $data = $this->client()->jsapiPay((string) $order['pay_no'], $payload);
        } catch (HnapaySdkException $e) {
            throw new PaymentException('新生支付JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['payInfo'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult('jsapi', (string) $order['pay_type_code'], $orgCode, 'jsapiPay', ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 新生支付方式编码。
     */
    private function orgCode(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'WECHATPAY',
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
            'chan_order_no' => (string) ($data['merOrderNum'] ?? $data['merOrderId'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['hnapayOrderId'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): HnapayClient
    {
        if ($this->client === null) {
            $this->client = new HnapayClient([
                'mer_id' => $this->configText('mer_id'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
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
