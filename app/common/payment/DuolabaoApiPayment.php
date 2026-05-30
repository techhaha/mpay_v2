<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\duolabao\DuolabaoClient;
use app\common\sdk\duolabao\DuolabaoSdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 哆啦宝支付 API 插件。
 */
class DuolabaoApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?DuolabaoClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'duolabao_api',
        'name' => '哆啦宝支付API',
        'author' => 'MPAY',
        'link' => 'http://www.duolabao.com/',
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
            ['type' => 'input', 'field' => 'agent_num', 'title' => '代理商编号', 'value' => ''],
            ['type' => 'input', 'field' => 'customer_num', 'title' => '客户编号', 'value' => '', 'validate' => [['required' => true, 'message' => '客户编号不能为空']]],
            ['type' => 'input', 'field' => 'shop_num', 'title' => '门店编号', 'value' => '', 'validate' => [['required' => true, 'message' => '门店编号不能为空']]],
            ['type' => 'input', 'field' => 'access_key', 'title' => 'AccessKey', 'value' => '', 'validate' => [['required' => true, 'message' => 'AccessKey不能为空']]],
            ['type' => 'password', 'field' => 'secret_key', 'title' => 'SecretKey', 'value' => '', 'validate' => [['required' => true, 'message' => 'SecretKey不能为空']]],
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

        try {
            $data = $this->client()->post('/api/generateQRCodeUrl', [
                'version' => 'V4.0',
                'agentNum' => $this->configText('agent_num'),
                'customerNum' => $this->configText('customer_num'),
                'shopNum' => $this->configText('shop_num'),
                'requestNum' => (string) $order['pay_no'],
                'orderAmount' => FormatHelper::amount((int) $order['amount']),
                'subOrderType' => 'NORMAL',
                'orderType' => 'SALES',
                'timeExpire' => date('Y-m-d H:i:s', time() + 7200),
                'businessType' => 'QRCODE_TRAD',
                'payModel' => 'ONCE',
                'source' => 'API',
                'callbackUrl' => (string) $order['callback_url'],
                'completeUrl' => (string) $order['return_url'],
                'clientIp' => (string) $order['client_ip'],
            ]);
        } catch (DuolabaoSdkException $e) {
            throw new PaymentException('哆啦宝下单失败：' . $e->getMessage(), 40200);
        }

        $qrcode = (string) ($data['url'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('哆啦宝未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], 'qrcode', 'generateQRCodeUrl', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
    }

    /**
     * 哆啦宝旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '哆啦宝插件暂不支持主动查单'];
    }

    /**
     * 哆啦宝旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '哆啦宝插件暂不支持关单'];
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
            $data = $this->client()->post('/api/refundByRequestNum', [
                'version' => 'V4.0',
                'agentNum' => $this->configText('agent_num'),
                'customerNum' => $this->configText('customer_num'),
                'shopNum' => $this->configText('shop_num'),
                'requestNum' => (string) $order['pay_no'],
                'refundPartAmount' => FormatHelper::amount((int) $order['refund_amount']),
                'refundRequestNum' => (string) $order['refund_no'],
                'extMap' => ['refund_status_type' => '1'],
            ]);
        } catch (DuolabaoSdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['orderNum'] ?? $order['refund_no']),
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
        $body = $request->rawBody();
        if (!$this->client()->verifyNotify($body, (string) $request->header('timestamp', ''), (string) $request->header('token', ''))) {
            throw new PaymentException('哆啦宝回调验签失败', 40200);
        }

        $payload = (array) json_decode($body, true);
        $success = (string) ($payload['status'] ?? '') === 'SUCCESS';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['status'] ?? ''),
            'channel_order_no' => (string) ($payload['requestNum'] ?? ''),
            'channel_trade_no' => (string) ($payload['orderNum'] ?? $payload['bankRequestNum'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回哆啦宝成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回哆啦宝失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'error';
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
        $bankType = $payType === 'wxpay' ? 'WX' : 'ALIPAY';
        $payload = [
            'version' => 'V4.0',
            'agentNum' => $this->configText('agent_num'),
            'customerNum' => $this->configText('customer_num'),
            'shopNum' => $this->configText('shop_num'),
            'requestNum' => (string) $order['pay_no'],
            'orderAmount' => FormatHelper::amount((int) $order['amount']),
            'orderType' => 'SALES',
            'bankType' => $bankType,
            'paySource' => $bankType,
            'authCode' => (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? ''),
            'callbackUrl' => (string) $order['callback_url'],
            'completeUrl' => (string) $order['return_url'],
            'clientIp' => (string) $order['client_ip'],
        ];
        if ((string) ($payment['sub_appid'] ?? '') !== '') {
            $payload['appId'] = (string) $payment['sub_appid'];
            $payload['subAppId'] = (string) $payment['sub_appid'];
        }

        try {
            $data = $this->client()->post('/api/createPayWithCheck', $payload);
        } catch (DuolabaoSdkException $e) {
            throw new PaymentException('哆啦宝JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['bankRequest'] ?? [];
        if (is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }
        if (!is_array($payInfo) || $payInfo === []) {
            throw new PaymentException('哆啦宝未返回JSAPI支付参数', 40200, ['response' => $data]);
        }

        return $this->payResult('jsapi', $payType, 'jsapi', 'createPayWithCheck', $payInfo + ['raw' => $data], $data, $order);
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
            'chan_order_no' => (string) ($data['requestNum'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['orderNum'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): DuolabaoClient
    {
        if ($this->client === null) {
            $this->client = new DuolabaoClient([
                'access_key' => $this->configText('access_key'),
                'secret_key' => $this->configText('secret_key'),
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
