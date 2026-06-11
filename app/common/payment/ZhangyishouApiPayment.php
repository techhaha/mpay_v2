<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\zhangyishou\ZhangyishouClient;
use app\common\sdk\zhangyishou\ZhangyishouSdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 掌易收聚合支付插件。
 */
class ZhangyishouApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    use DirectPaymentProductSelectorTrait;

    private const PRODUCT_PAY_CHANNEL_ID = 'pay_channel_id';
    private const PRODUCT_WXPAY_MOBILE_CHANNEL_ID = 'wxpay_mobile_channel_id';

    private ?ZhangyishouClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'zhangyishou_api',
        'name' => '掌易收聚合支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
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
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_PAY_CHANNEL_ID => '默认通道ID',
                self::PRODUCT_WXPAY_MOBILE_CHANNEL_ID => '微信移动端通道ID',
            ]),
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
        return $this->executeDirectPaymentProduct($order, [
            'h5' => [
                'products' => [
                    'alipay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'qqpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'wxpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'bank' => self::PRODUCT_PAY_CHANNEL_ID,
                ],
                'handler' => fn (): array => $this->createPay($order, false, 'jump'),
            ],

            'jump' => [
                'products' => [
                    'alipay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'qqpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'wxpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'bank' => self::PRODUCT_PAY_CHANNEL_ID,
                ],
                'handler' => fn (): array => $this->createPay($order, false, 'jump'),
            ],

            'web' => [
                'products' => [
                    'alipay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'qqpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'wxpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'bank' => self::PRODUCT_PAY_CHANNEL_ID,
                ],
                'handler' => fn (): array => $this->createPay($order, false, 'jump'),
            ],

            'urlscheme' => [
                'products' => [
                    'wxpay' => self::PRODUCT_WXPAY_MOBILE_CHANNEL_ID,
                ],
                'handler' => fn (): array => $this->createPay($order, true, 'urlscheme'),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'qqpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'wxpay' => self::PRODUCT_PAY_CHANNEL_ID,
                    'bank' => self::PRODUCT_PAY_CHANNEL_ID,
                ],
                'handler' => fn (): array => $this->createPay($order, false, 'qrcode'),
            ],
        ], '掌易收');
    }

    /**
     * 创建掌易收支付单。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param bool $useMobileChannel 是否使用微信移动端专用通道
     * @param string $payPage 承接页类型
     * @return array<string, mixed>
     */
    private function createPay(array $order, bool $useMobileChannel, string $payPage): array
    {
        $payType = (string) $order['pay_type_code'];
        if ($useMobileChannel && ($payType !== 'wxpay' || $this->configText('wxpay_mobile_channel_id') === '')) {
            throw new PaymentException('掌易收当前支付方式不支持URL Scheme产品', 40200, ['channel_error_code' => 'PRODUCT_NOT_OPEN']);
        }

        $payChannelId = $useMobileChannel ? $this->configText('wxpay_mobile_channel_id') : $this->configText('pay_channel_id');

        try {
            $data = $this->client()->addOrder([
                'MerchantId' => $this->configText('merchant_id'),
                'DownstreamOrderNo' => (string) $order['pay_no'],
                'OrderTime' => date('Y-m-d H:i:s'),
                'PayChannelId' => $payChannelId,
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

        return [
            'pay_page' => $payPage,
            'pay_type' => $payType,
            'pay_product' => $payChannelId,
            'pay_action' => 'Order.AddOrder',
            'pay_params' => $payPage === 'qrcode'
                ? ['qrcode' => $url, 'raw' => $data]
                : ($payPage === 'urlscheme'
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
