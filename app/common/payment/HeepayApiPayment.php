<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\heepay\HeepayClient;
use app\common\sdk\heepay\HeepaySdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 汇付宝支付 API 插件。
 */
class HeepayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?HeepayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'heepay_api',
        'name' => '汇付宝支付API',
        'author' => 'MPAY',
        'link' => 'https://www.heepay.com/',
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
            ['type' => 'input', 'field' => 'agent_id', 'title' => '商户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户编号不能为空']]],
            ['type' => 'input', 'field' => 'ref_agent_id', 'title' => '二级商户号', 'value' => ''],
            ['type' => 'input', 'field' => 'bank_id', 'title' => '上游商户BankId', 'value' => ''],
            ['type' => 'password', 'field' => 'pay_key', 'title' => '支付密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '支付密钥不能为空']]],
            ['type' => 'password', 'field' => 'refund_key', 'title' => '退款密钥', 'value' => ''],
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
        $payCode = match ($payType) {
            'wxpay' => '30',
            'bank' => $method === 'h5' ? '34' : ($method === 'web' ? '20' : '64'),
            default => '22',
        };

        $url = $this->client()->payUrl($this->basePayload($order) + ['pay_type' => $payCode], $payCode === '20');

        return [
            'pay_page' => 'jump',
            'pay_type' => $payType,
            'pay_product' => $payCode,
            'pay_action' => 'Payment/Index',
            'pay_params' => ['url' => $url],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 汇付宝旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '汇付宝插件暂不支持主动查单'];
    }

    /**
     * 汇付宝旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '汇付宝插件暂不支持关单'];
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
            $data = $this->client()->refund([
                'pay_no' => (string) $order['pay_no'],
                'refund_no' => (string) $order['refund_no'],
                'amount' => (int) $order['amount'],
                'refund_amount' => (int) $order['refund_amount'],
                'notify_url' => (string) ($order['callback_url'] ?? ''),
            ]);
        } catch (HeepaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        if ((string) ($data['ret_code'] ?? '') !== '0000') {
            return ['success' => false, 'msg' => (string) ($data['ret_msg'] ?? '退款失败'), 'raw_data' => $data];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) $order['refund_no'],
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
        $payload = $request->get();
        if (!$this->client()->verifyNotify($payload)) {
            throw new PaymentException('汇付宝回调验签失败', 40200);
        }

        $success = (string) ($payload['result'] ?? '') === '1';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['result'] ?? ''),
            'channel_order_no' => (string) ($payload['agent_bill_id'] ?? ''),
            'channel_trade_no' => (string) ($payload['jnet_bill_no'] ?? ''),
            'channel_status' => (string) ($payload['result'] ?? ''),
        ];
    }

    /**
     * 返回汇付宝成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'ok';
    }

    /**
     * 返回汇付宝失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'error';
    }

    /**
     * 构造支付参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function basePayload(array $order): array
    {
        return [
            'pay_no' => (string) $order['pay_no'],
            'amount' => (int) $order['amount'],
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) $order['return_url'],
            'client_ip' => (string) $order['client_ip'],
            'subject' => (string) $order['subject'],
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): HeepayClient
    {
        if ($this->client === null) {
            $this->client = new HeepayClient([
                'agent_id' => $this->configText('agent_id'),
                'ref_agent_id' => $this->configText('ref_agent_id'),
                'bank_id' => $this->configText('bank_id'),
                'pay_key' => $this->configText('pay_key'),
                'refund_key' => $this->configText('refund_key'),
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
