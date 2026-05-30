<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\yinyingtong\YinyingtongClient;
use app\common\sdk\yinyingtong\YinyingtongSdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 银盈通支付 API 插件。
 */
class YinyingtongApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?YinyingtongClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'yinyingtong_api',
        'name' => '银盈通支付API',
        'author' => 'MPAY',
        'link' => 'http://www.yinyingtong.com/',
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
            ['type' => 'input', 'field' => 'app_id', 'title' => '应用ID', 'value' => '', 'validate' => [['required' => true, 'message' => '应用ID不能为空']]],
            ['type' => 'password', 'field' => 'app_key', 'title' => '应用KEY', 'value' => '', 'validate' => [['required' => true, 'message' => '应用KEY不能为空']]],
            ['type' => 'password', 'field' => 'product_key', 'title' => '产品密钥', 'value' => '', 'validate' => [['required' => true, 'message' => '产品密钥不能为空']]],
            ['type' => 'input', 'field' => 'merchant_number', 'title' => '交易商户企业号', 'value' => '', 'validate' => [['required' => true, 'message' => '交易商户企业号不能为空']]],
            ['type' => 'input', 'field' => 'trade_platform_no', 'title' => '平台商企业号', 'value' => '', 'validate' => [['required' => true, 'message' => '平台商企业号不能为空']]],
            ['type' => 'input', 'field' => 'channel_merch_no', 'title' => '渠道商户号', 'value' => ''],
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

        if ($payType === 'bank') {
            return $this->quickPay($order);
        }
        if ($payType === 'wxpay' && in_array($method, ['app', 'applet', 'mini'], true)) {
            return $this->wxUrlSchemePay($order);
        }

        $prepay = $this->prepay($order, $payType === 'wxpay' ? '02' : '01', $payType === 'wxpay' ? '16' : '');
        $orderId = (string) ($prepay['order_id'] ?? '');
        if ($orderId === '') {
            throw new PaymentException('银盈通未返回预下单号', 40200, ['response' => $prepay]);
        }

        $url = $payType === 'wxpay'
            ? 'https://h5.gomepay.com/cashier-h5/index.html#/pages/preOrder/wxPublicOrder?orderId=' . rawurlencode($orderId) . '&showPayButton=0'
            : 'https://h5.gomepay.com/cashier-h5/index.html#/pages/preOrder/orderPay?orderId=' . rawurlencode($orderId) . '&showPayButton=0';

        $payPage = $method === 'h5' || $method === 'web' ? 'jump' : 'qrcode';
        $payParams = $payPage === 'jump'
            ? ['url' => $url, 'raw' => $prepay]
            : ['qrcode' => $url, 'raw' => $prepay];

        return $this->payResult($payPage, $payType, $payType === 'wxpay' ? 'wx_public_order' : 'alipay_order', 'gepos.pre.pay', $payParams, $prepay, $order);
    }

    /**
     * 银盈通旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '银盈通插件暂不支持主动查单'];
    }

    /**
     * 银盈通旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '银盈通插件暂不支持关单'];
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
            $data = $this->client()->execute('gepos.refund', [
                'scene' => '0606',
                'merchant_number' => $this->configText('merchant_number'),
                'order_number' => (string) $order['refund_no'],
                'old_order_number' => (string) $order['pay_no'],
                'old_order_id' => (string) ($order['chan_trade_no'] ?? ''),
                'amount' => FormatHelper::amount((int) $order['refund_amount']),
                'currency' => 'CNY',
                'async_notification_addr' => (string) ($order['callback_url'] ?? ''),
                'memo' => '订单退款',
            ], (string) ($order['client_ip'] ?? ''), (string) ($order['_env'] ?? 'pc'));
        } catch (YinyingtongSdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['order_id'] ?? $order['refund_no']),
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
        $encrypted = (string) ($request->post('dstbdatasign') ?? '');
        if ($encrypted === '') {
            $payload = json_decode((string) $request->rawBody(), true);
            if (!is_array($payload) || !$this->client()->verify($payload)) {
                throw new PaymentException('银盈通回调验签失败', 40200);
            }
            $data = json_decode((string) ($payload['data'] ?? '{}'), true);
            if (!is_array($data)) {
                throw new PaymentException('银盈通回调内容不是合法 JSON', 40200);
            }
        } else {
            try {
                $data = $this->client()->decryptNotify($encrypted);
            } catch (YinyingtongSdkException $e) {
                throw new PaymentException('银盈通回调解密失败：' . $e->getMessage(), 40200);
            }
        }

        $status = (string) ($data['orderstatus'] ?? $data['status'] ?? '');
        $success = in_array($status, ['00', 'SUCCESS'], true);

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => $status,
            'channel_order_no' => (string) ($data['dsorderid'] ?? $data['order_number'] ?? ''),
            'channel_trade_no' => (string) ($data['orderid'] ?? $data['order_id'] ?? ''),
            'channel_status' => $status,
        ];
    }

    /**
     * 返回银盈通成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '00';
    }

    /**
     * 返回银盈通失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '01';
    }

    /**
     * 微信小程序 URL Scheme 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function wxUrlSchemePay(array $order): array
    {
        $prepay = $this->prepay($order, '02', '22');
        $orderId = (string) ($prepay['order_id'] ?? '');
        if ($orderId === '') {
            throw new PaymentException('银盈通未返回预下单号', 40200, ['response' => $prepay]);
        }

        $query = 'orderId=' . rawurlencode($orderId) . '&showPayButton=0';
        $urlScheme = 'weixin://dl/business/?appid=wx135edf7e3c7a1e7d&path=pages/wechat/preOrder/orderpay&query=' . rawurlencode($query) . '&env_version=release';

        return $this->payResult('urlscheme', 'wxpay', 'wx_mini', 'gepos.pre.pay', ['urlscheme' => $urlScheme, 'raw' => $prepay], $prepay, $order);
    }

    /**
     * 快捷支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function quickPay(array $order): array
    {
        $userId = substr((string) $order['pay_no'], -16);
        try {
            $data = $this->client()->execute('gcash.trade.precreate', [
                'merchant_number' => $this->configText('merchant_number'),
                'order_number' => (string) $order['pay_no'],
                'scene' => '14',
                'good_desc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'total_amount' => FormatHelper::amount((int) $order['amount']),
                'currency' => 'cny',
                'user_id' => $userId,
                'notify_url' => (string) $order['callback_url'],
                'return_url' => (string) $order['return_url'],
            ], (string) $order['client_ip'], (string) ($order['_env'] ?? 'pc'));
        } catch (YinyingtongSdkException $e) {
            throw new PaymentException('银盈通快捷支付下单失败：' . $e->getMessage(), 40200);
        }

        $url = 'https://h5.gomepay.com/cashier-h5/index.html#/pages/paymentB/cashRegister?' . http_build_query([
            'merchant_number' => $this->configText('merchant_number'),
            'user_id' => $userId,
            'order_number' => (string) $order['pay_no'],
            'type' => 'wbsh',
        ]);

        return $this->payResult('jump', 'bank', 'quick_pay', 'gcash.trade.precreate', ['url' => $url, 'raw' => $data], $data, $order);
    }

    /**
     * 支付预下单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function prepay(array $order, string $payType, string $bankServiceType): array
    {
        try {
            return $this->client()->execute('gepos.pre.pay', array_filter([
                'merchant_number' => $this->configText('merchant_number'),
                'order_number' => (string) $order['pay_no'],
                'amount' => FormatHelper::amount((int) $order['amount']),
                'pay_type' => $payType,
                'currency' => 'CNY',
                'order_title' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'channel_code' => $payType,
                'async_notification_addr' => (string) $order['callback_url'],
                'notify_key_mode' => '03',
                'ref_no' => $this->configText('trade_platform_no'),
                'bank_service_type' => $bankServiceType,
                'bank_mch_id' => $this->channelMerchantNo(),
            ], static fn (mixed $value): bool => $value !== '' && $value !== null), (string) $order['client_ip'], (string) ($order['_env'] ?? 'pc'));
        } catch (YinyingtongSdkException $e) {
            throw new PaymentException('银盈通预下单失败：' . $e->getMessage(), 40200);
        }
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
            'chan_order_no' => (string) ($data['order_number'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['order_id'] ?? ''),
        ];
    }

    /**
     * 渠道商户号支持按逗号配置多个值，用于银盈通多渠道号轮询。
     */
    private function channelMerchantNo(): string
    {
        $value = $this->configText('channel_merch_no');
        if (!str_contains($value, ',')) {
            return $value;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $value))));
        return $items === [] ? '' : (string) $items[array_rand($items)];
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): YinyingtongClient
    {
        if ($this->client === null) {
            $this->client = new YinyingtongClient([
                'app_id' => $this->configText('app_id'),
                'app_key' => $this->configText('app_key'),
                'product_key' => $this->configText('product_key'),
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
