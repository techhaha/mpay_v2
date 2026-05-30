<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\xunhupay\XunhupayClient;
use app\common\sdk\xunhupay\XunhupaySdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 虎皮椒支付插件。
 */
class XunhupayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?XunhupayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'xunhupay_api',
        'name' => '虎皮椒支付API',
        'author' => 'MPAY',
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
            [
                'type' => 'input',
                'field' => 'appid',
                'title' => '商户ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户ID不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'api_key',
                'title' => 'API密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => 'API密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_url',
                'title' => '网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '留空使用虎皮椒默认网关',
                ],
            ],
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
        $channelPayment = $payType === 'wxpay' ? 'wechat' : 'alipay';

        $params = [
            'version' => '1.1',
            'trade_order_id' => (string) $order['pay_no'],
            'payment' => $channelPayment,
            'total_fee' => FormatHelper::amount((int) $order['amount']),
            'title' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'notify_url' => (string) $order['callback_url'],
            'return_url' => (string) $order['return_url'],
        ];

        $isMobile = in_array((string) ($order['_env'] ?? ''), ['mobile', 'wechat', 'alipay', 'qq'], true);
        if ($channelPayment === 'wechat' && $isMobile) {
            $params['type'] = 'WAP';
            $params['wap_url'] = parse_url((string) $order['return_url'], PHP_URL_HOST) ?: '';
            $params['wap_name'] = (string) $order['subject'];
        }

        try {
            $data = $this->client()->pay($params);
        } catch (XunhupaySdkException $e) {
            throw new PaymentException('虎皮椒下单失败：' . $e->getMessage(), 40200);
        }

        if ($isMobile && (string) ($data['url'] ?? '') !== '') {
            return $this->payResult('jump', $payType, $channelPayment, ['url' => (string) $data['url'], 'raw' => $data], $data, $order);
        }

        $qrcodeImage = (string) ($data['url_qrcode'] ?? '');
        if ($qrcodeImage === '') {
            throw new PaymentException('虎皮椒未返回二维码地址', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $channelPayment, [
            'qrcode' => $this->client()->parseQrcode($qrcodeImage),
            'raw' => $data,
        ], $data, $order);
    }

    /**
     * 查询支付订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->query([
                'trade_order_id' => (string) $order['pay_no'],
            ]);
        } catch (XunhupaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        $status = (string) ($data['status'] ?? '') === 'OD'
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($data['trade_order_id'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['open_order_id'] ?? $order['chan_trade_no'] ?? ''),
            'channel_status' => (string) ($data['status'] ?? ''),
            'message' => (string) ($data['status'] ?? ''),
            'raw_data' => $data,
        ];
    }

    /**
     * 虎皮椒旧插件未提供关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '虎皮椒插件暂不支持关单',
        ];
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
                'open_order_id' => (string) ($order['chan_trade_no'] ?? ''),
            ]);
        } catch (XunhupaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['transaction_id'] ?? $order['refund_no']),
            'refund_amount' => (int) round(((float) ($data['refund_fee'] ?? 0)) * 100),
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
        if (!$this->client()->verify($payload)) {
            throw new PaymentException('虎皮椒回调验签失败', 40200);
        }

        $success = (string) ($payload['status'] ?? '') === 'OD';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($payload['status'] ?? ''),
            'channel_order_no' => (string) ($payload['trade_order_id'] ?? ''),
            'channel_trade_no' => (string) ($payload['open_order_id'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回虎皮椒成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回虎皮椒失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 包装标准支付结果。
     *
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payResult(string $page, string $payType, string $product, array $payParams, array $data, array $order): array
    {
        return [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => 'payment.do',
            'pay_params' => $payParams,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['open_order_id'] ?? ''),
        ];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): XunhupayClient
    {
        if ($this->client === null) {
            $this->client = new XunhupayClient([
                'appid' => $this->configText('appid'),
                'api_key' => $this->configText('api_key'),
                'api_url' => $this->configText('api_url'),
            ]);
        }

        return $this->client;
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
