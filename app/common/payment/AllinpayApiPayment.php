<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\allinpay\AllinpayClient;
use app\common\sdk\allinpay\AllinpaySdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 通联支付 API 插件。
 *
 * 迁移彩虹 `allinpay` 的统一支付、JSAPI、回调和退款主链路。
 */
class AllinpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private const PAY_URL = 'https://vsp.allinpay.com/apiweb/unitorder/pay';
    private const REFUND_URL = 'https://vsp.allinpay.com/apiweb/tranx/refund';
    private const CASHIER_URL = 'https://syb.allinpay.com/apiweb/h5unionpay/unionorder';

    private const PRODUCT_ALIPAY_SCAN = 'alipay_scan';
    private const PRODUCT_ALIPAY_JSAPI = 'alipay_jsapi';
    private const PRODUCT_WXPAY_SCAN = 'wxpay_scan';
    private const PRODUCT_WXPAY_JSAPI = 'wxpay_jsapi';
    private const PRODUCT_QQPAY_SCAN = 'qqpay_scan';
    private const PRODUCT_BANK_SCAN = 'bank_scan';
    private const PRODUCT_BANK_JSAPI = 'bank_jsapi';
    private const PRODUCT_CASHIER = 'cashier';

    private ?AllinpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'allinpay_api',
        'name' => '通联支付API',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'qqpay', 'bank'],
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
                'field' => 'merchant_no',
                'title' => '商户号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户号不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'app_id',
                'title' => '应用ID',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '应用ID不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '通联公钥',
                'value' => '',
                'props' => ['rows' => 4],
                'validate' => [
                    ['required' => true, 'message' => '通联公钥不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户私钥',
                'value' => '',
                'props' => ['rows' => 5],
                'validate' => [
                    ['required' => true, 'message' => '商户私钥不能为空'],
                ],
            ],
            [
                'type' => 'checkbox',
                'field' => 'enabled_products',
                'title' => '已开通产品',
                'value' => [self::PRODUCT_ALIPAY_SCAN, self::PRODUCT_WXPAY_SCAN, self::PRODUCT_BANK_SCAN],
                'options' => [
                    ['label' => '支付宝扫码', 'value' => self::PRODUCT_ALIPAY_SCAN],
                    ['label' => '支付宝JSAPI', 'value' => self::PRODUCT_ALIPAY_JSAPI],
                    ['label' => '微信扫码', 'value' => self::PRODUCT_WXPAY_SCAN],
                    ['label' => '微信JSAPI', 'value' => self::PRODUCT_WXPAY_JSAPI],
                    ['label' => 'QQ钱包扫码', 'value' => self::PRODUCT_QQPAY_SCAN],
                    ['label' => '云闪付扫码', 'value' => self::PRODUCT_BANK_SCAN],
                    ['label' => '云闪付JSAPI', 'value' => self::PRODUCT_BANK_JSAPI],
                    ['label' => 'H5收银台', 'value' => self::PRODUCT_CASHIER],
                ],
                'validate' => [
                    ['required' => true, 'message' => '已开通产品不能为空'],
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
        $method = (string) ($order['extra']['payment']['method'] ?? '');

        if ($method === 'h5') {
            return $this->cashierPay($order);
        }
        if ($payType === 'alipay' && $method === 'jsapi') {
            return $this->jsapiPay($order, self::PRODUCT_ALIPAY_JSAPI, 'A02');
        }
        if ($payType === 'wxpay' && $method === 'jsapi') {
            return $this->jsapiPay($order, self::PRODUCT_WXPAY_JSAPI, 'W02');
        }
        if ($payType === 'bank' && $method === 'jsapi') {
            return $this->jumpPay($order, self::PRODUCT_BANK_JSAPI, 'U02');
        }
        if ($payType === 'qqpay') {
            return $this->qrcodePay($order, self::PRODUCT_QQPAY_SCAN, 'Q01');
        }
        if ($payType === 'bank') {
            return $this->qrcodePay($order, self::PRODUCT_BANK_SCAN, 'U01');
        }
        if ($payType === 'wxpay') {
            return $this->qrcodePay($order, self::PRODUCT_WXPAY_SCAN, 'W01');
        }

        return $this->qrcodePay($order, self::PRODUCT_ALIPAY_SCAN, 'A01');
    }

    /**
     * 通联旧插件未提供主动查单链路。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => '通联插件暂不支持主动查单',
        ];
    }

    /**
     * 通联旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '通联插件暂不支持关单',
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
            $data = $this->client()->submit(self::REFUND_URL, [
                'trxamt' => (string) (int) $order['refund_amount'],
                'reqsn' => (string) $order['refund_no'],
                'oldtrxid' => (string) ($order['chan_trade_no'] ?? ''),
            ]);
        } catch (AllinpaySdkException $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['trxid'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['fee'] ?? $order['refund_amount']),
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
            throw new PaymentException('通联回调验签失败', 40200);
        }

        $success = (string) ($payload['trxstatus'] ?? '') === '0000';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['errmsg'] ?? $payload['trxstatus'] ?? ''),
            'channel_order_no' => (string) ($payload['cusorderid'] ?? ''),
            'channel_trade_no' => (string) ($payload['trxid'] ?? $payload['chnltrxid'] ?? ''),
            'channel_status' => (string) ($payload['trxstatus'] ?? ''),
        ];
    }

    /**
     * 返回通联成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回通联失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 扫码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 通联支付类型
     * @return array<string, mixed>
     */
    private function qrcodePay(array $order, string $product, string $payType): array
    {
        $data = $this->requestUnitOrder($order, $product, $payType);
        $qrcode = (string) ($data['payinfo'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('通联扫码下单未返回二维码内容', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'qrcode',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'unitorder.pay',
            'pay_params' => [
                'qrcode' => $qrcode,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trxid'] ?? ''),
        ];
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 通联支付类型
     * @return array<string, mixed>
     */
    private function jsapiPay(array $order, string $product, string $payType): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $userId = (string) ($payment['buyer_id'] ?? $payment['openid'] ?? $payment['sub_openid'] ?? '');
        if ($userId === '') {
            throw new PaymentException('通联JSAPI支付缺少用户标识', 40200);
        }

        $data = $this->requestUnitOrder($order, $product, $payType, $userId, (string) ($payment['sub_appid'] ?? ''));
        $payInfo = (string) ($data['payinfo'] ?? '');
        $params = json_decode($payInfo, true);
        if (!is_array($params)) {
            $params = ['tradeNO' => $payInfo];
        }
        $params['raw'] = $data;

        return [
            'pay_page' => 'jsapi',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'unitorder.pay',
            'pay_params' => $params,
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trxid'] ?? ''),
        ];
    }

    /**
     * 跳转类支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 通联支付类型
     * @return array<string, mixed>
     */
    private function jumpPay(array $order, string $product, string $payType): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $data = $this->requestUnitOrder($order, $product, $payType, (string) ($payment['buyer_id'] ?? ''));
        $url = (string) ($data['payinfo'] ?? '');
        if ($url === '') {
            throw new PaymentException('通联跳转支付未返回支付地址', 40200, ['response' => $data]);
        }

        return [
            'pay_page' => 'jump',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $product,
            'pay_action' => 'unitorder.pay',
            'pay_params' => [
                'url' => $url,
                'raw' => $data,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['trxid'] ?? ''),
        ];
    }

    /**
     * H5 收银台。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function cashierPay(array $order): array
    {
        $this->ensureProduct(self::PRODUCT_CASHIER);

        $payload = $this->client()->cashierPayload([
            'trxamt' => (string) (int) $order['amount'],
            'reqsn' => (string) $order['pay_no'],
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'validtime' => '30',
            'notify_url' => (string) $order['callback_url'],
            'returl' => (string) $order['return_url'],
            'charset' => 'UTF-8',
        ]);

        $html = '<form action="' . self::CASHIER_URL . '" method="post" id="dopay">';
        foreach ($payload as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</form><script>document.getElementById("dopay").submit();</script>';

        return [
            'pay_page' => 'html',
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => self::PRODUCT_CASHIER,
            'pay_action' => 'cashier',
            'pay_params' => [
                'html' => $html,
            ],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 请求通联统一支付接口。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param string $product 插件产品
     * @param string $payType 通联支付类型
     * @param string $userId 用户标识
     * @param string $subAppId 子应用 ID
     * @return array<string, mixed>
     */
    private function requestUnitOrder(array $order, string $product, string $payType, string $userId = '', string $subAppId = ''): array
    {
        $this->ensureProduct($product);

        $payload = [
            'trxamt' => (string) (int) $order['amount'],
            'reqsn' => (string) $order['pay_no'],
            'paytype' => $payType,
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'validtime' => '30',
            'notify_url' => (string) $order['callback_url'],
            'cusip' => (string) $order['client_ip'],
        ];
        if ($subAppId !== '') {
            $payload['sub_appid'] = $subAppId;
        }
        if ($userId !== '') {
            $payload['acct'] = $userId;
            $payload['front_url'] = (string) $order['return_url'];
        }

        try {
            $data = $this->client()->submit(self::PAY_URL, $payload);
        } catch (AllinpaySdkException $e) {
            throw new PaymentException('通联下单失败：' . $e->getMessage(), 40200);
        }
        if ((string) ($data['trxstatus'] ?? '') !== '0000') {
            throw new PaymentException('通联下单失败：' . (string) ($data['errmsg'] ?? '渠道返回失败'), 40200, [
                'response' => $data,
            ]);
        }

        return $data;
    }

    /**
     * 获取通联客户端。
     */
    private function client(): AllinpayClient
    {
        if ($this->client === null) {
            $this->client = new AllinpayClient([
                'merchant_no' => $this->configText('merchant_no'),
                'app_id' => $this->configText('app_id'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
            ]);
        }

        return $this->client;
    }

    /**
     * 校验产品开关。
     */
    private function ensureProduct(string $product): void
    {
        if (!in_array($product, $this->enabledProducts(), true)) {
            throw new PaymentException('当前通联通道未开启该支付产品', 40200, ['product' => $product]);
        }
    }

    /**
     * 获取启用产品列表。
     *
     * @return array<int, string>
     */
    private function enabledProducts(): array
    {
        $products = $this->getConfig('enabled_products', []);
        if (is_string($products)) {
            $decoded = json_decode($products, true);
            $products = is_array($decoded) ? $decoded : explode(',', $products);
        }
        if (!is_array($products)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $products
        )));
    }

    /**
     * 获取字符串配置。
     */
    private function configText(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
