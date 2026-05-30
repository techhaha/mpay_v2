<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\umfpay\UmfpayClient;
use app\common\sdk\umfpay\UmfpaySdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 联动优势支付 API 插件。
 */
class UmfpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?UmfpayClient $client = null;

    /**
     * 最近一次回调参数，用于生成联动优势要求的签名应答。
     *
     * @var array<string, mixed>
     */
    private array $lastNotifyPayload = [];

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'umfpay_api',
        'name' => '联动优势支付API',
        'author' => 'MPAY',
        'link' => 'https://xy.umfintech.com/',
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
            ['type' => 'input', 'field' => 'mer_id', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '平台公钥 PEM', 'value' => '', 'validate' => [['required' => true, 'message' => '平台公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥 PEM', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
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
        if ($payType === 'wxpay' && $method === 'jsapi') {
            $url = $this->client()->payUrl($this->basePayload($order) + [
                'service' => 'publicnumber_and_verticalcode',
                'ret_url' => (string) $order['return_url'],
                'is_public_number' => 'Y',
            ]);
            return $this->payResult('jump', $payType, 'jsapi', 'publicnumber_and_verticalcode', ['url' => $url], [], $order);
        }

        $scanType = match ($payType) {
            'wxpay' => 'WECHAT',
            'bank' => 'UNION',
            default => 'ALIPAY',
        };

        try {
            $data = $this->client()->submit($this->basePayload($order) + [
                'service' => 'active_scancode_order_new',
                'scancode_type' => $scanType,
                'mer_flag' => 'KMER',
                'consumer_id' => str_replace('.', '', (string) $order['client_ip']),
            ]);
        } catch (UmfpaySdkException $e) {
            throw new PaymentException('联动优势下单失败：' . $e->getMessage(), 40200);
        }

        if ((string) ($data['ret_code'] ?? '') !== '0000') {
            throw new PaymentException('联动优势下单失败：' . (string) ($data['ret_msg'] ?? ''), 40200, ['response' => $data]);
        }

        $qrcode = base64_decode((string) ($data['bank_payurl'] ?? ''), true) ?: '';
        if ($qrcode === '') {
            throw new PaymentException('联动优势未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $scanType, 'active_scancode_order_new', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 联动优势旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '联动优势插件暂不支持主动查单'];
    }

    /**
     * 联动优势旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '联动优势插件暂不支持关单'];
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
            $data = $this->client()->submit([
                'service' => 'mer_refund',
                'refund_no' => (string) $order['refund_no'],
                'order_id' => (string) $order['pay_no'],
                'mer_date' => substr((string) $order['pay_no'], 3, 8),
                'org_amount' => (string) (int) $order['amount'],
                'refund_amount' => (string) (int) $order['refund_amount'],
            ]);
        } catch (UmfpaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        if ((string) ($data['ret_code'] ?? '') !== '0000') {
            return ['success' => false, 'msg' => (string) ($data['ret_msg'] ?? '退款失败'), 'raw_data' => $data];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['order_id'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['refund_amt'] ?? $order['refund_amount']),
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
        $payload = $request->get();
        $this->lastNotifyPayload = $payload;
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('联动优势回调验签失败', 40200);
        }

        $success = (string) ($payload['trade_state'] ?? '') === 'TRADE_SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['trade_state'] ?? ''),
            'channel_order_no' => (string) ($payload['order_id'] ?? ''),
            'channel_trade_no' => (string) ($payload['trade_no'] ?? ''),
            'channel_status' => (string) ($payload['trade_state'] ?? ''),
        ];
    }

    /**
     * 返回联动优势成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return $this->client()->responseHtml($this->lastNotifyPayload, '0000', 'success');
    }

    /**
     * 返回联动优势失败应答。
     */
    public function notifyFail(): string|Response
    {
        return $this->client()->responseHtml($this->lastNotifyPayload, '0001', 'fail');
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
            'notify_url' => (string) $order['callback_url'],
            'goods_inf' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'order_id' => (string) $order['pay_no'],
            'mer_date' => date('Ymd'),
            'amount' => (string) (int) $order['amount'],
            'user_ip' => (string) $order['client_ip'],
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
            'chan_order_no' => (string) ($data['order_id'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['trade_no'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): UmfpayClient
    {
        if ($this->client === null) {
            $this->client = new UmfpayClient([
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
