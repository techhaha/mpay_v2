<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\zhangyishou\ZhangyishouClient;
use app\common\sdk\zhangyishou\ZhangyishouSdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 掌易收聚合支付插件。
 */
class ZhangyishouApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?ZhangyishouClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'zhangyishou_api',
        'name' => '掌易收聚合支付API',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'qqpay', 'wxpay', 'bank'],
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
                'field' => 'merchant_id',
                'title' => '登录账号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '登录账号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_no',
                'title' => '商户编号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户编号不能为空'],
                ],
            ],
            [
                'type' => 'password',
                'field' => 'api_key',
                'title' => '商户密钥',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pay_channel_id',
                'title' => '通道ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '通道ID不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'wxpay_mobile_channel_id',
                'title' => '微信移动端通道ID',
                'value' => '',
                'props' => [
                    'placeholder' => '微信移动端需单独走小程序/Scheme时填写',
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
        $isWxMobile = $payType === 'wxpay'
            && $this->configText('wxpay_mobile_channel_id') !== ''
            && in_array((string) ($order['_env'] ?? ''), ['mobile', 'wechat'], true);

        try {
            $data = $this->client()->addOrder([
                'MerchantId' => $this->configText('merchant_id'),
                'DownstreamOrderNo' => (string) $order['pay_no'],
                'OrderTime' => date('Y-m-d H:i:s'),
                'PayChannelId' => $isWxMobile ? $this->configText('wxpay_mobile_channel_id') : $this->configText('pay_channel_id'),
                'AsynPath' => (string) $order['callback_url'],
                'OrderMoney' => FormatHelper::amount((int) $order['amount']),
                'IPPath' => (string) $order['client_ip'],
                'Mproductdesc' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            ]);
        } catch (ZhangyishouSdkException $e) {
            throw new PaymentException('掌易收下单失败：' . $e->getMessage(), 40200);
        }

        $url = (string) ($data['Info'] ?? '');
        if ($url === '') {
            throw new PaymentException('掌易收未返回支付地址', 40200, ['response' => $data]);
        }

        $page = $this->payPage($payType, $isWxMobile, (string) ($order['_env'] ?? ''));

        return [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $isWxMobile ? $this->configText('wxpay_mobile_channel_id') : $this->configText('pay_channel_id'),
            'pay_action' => 'Order.AddOrder',
            'pay_params' => $page === 'qrcode'
                ? ['qrcode' => $url, 'raw' => $data]
                : ($page === 'urlscheme'
                    ? ['urlscheme' => $url, 'raw' => $data]
                    : ['url' => $url, 'raw' => $data]),
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 掌易收旧插件未提供主动查单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => '掌易收插件暂不支持主动查单',
        ];
    }

    /**
     * 掌易收旧插件未提供关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '掌易收插件暂不支持关单',
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
                'MerchantId' => $this->configText('merchant_id'),
                'MerchantOrder' => (string) $order['pay_no'],
                'RefundAmount' => FormatHelper::amount((int) $order['refund_amount']),
            ]);
        } catch (ZhangyishouSdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['RefundNo'] ?? $order['refund_no']),
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
        $payload = (array) json_decode($request->rawBody(), true);
        if ($payload === [] || !$this->client()->verify($payload)) {
            throw new PaymentException('掌易收回调验签失败', 40200);
        }

        $success = (string) ($payload['OrderState'] ?? '') === '1';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['Remark'] ?? $payload['OrderState'] ?? ''),
            'channel_order_no' => (string) ($payload['DownstreamOrderNo'] ?? ''),
            'channel_trade_no' => (string) ($payload['OrderNo'] ?? ''),
            'channel_status' => (string) ($payload['OrderState'] ?? ''),
        ];
    }

    /**
     * 返回掌易收成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'OK';
    }

    /**
     * 返回掌易收失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'ERROR';
    }

    /**
     * 根据支付方式和环境选择承接页类型。
     */
    private function payPage(string $payType, bool $isWxMobile, string $env): string
    {
        if ($isWxMobile) {
            return 'urlscheme';
        }
        if (($payType === 'alipay' && $env === 'alipay') || ($payType === 'wxpay' && $env === 'wechat') || ($payType === 'qqpay' && $env === 'qq')) {
            return 'jump';
        }

        return 'qrcode';
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): ZhangyishouClient
    {
        if ($this->client === null) {
            $this->client = new ZhangyishouClient([
                'merchant_no' => $this->configText('merchant_no'),
                'api_key' => $this->configText('api_key'),
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
