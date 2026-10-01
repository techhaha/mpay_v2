<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentIdentityRequirementInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\jlpay\JlpayClient;
use app\common\sdk\jlpay\JlpaySdkException;
use app\common\trait\DirectPaymentProductSelectorTrait;
use app\common\trait\DirectPaymentIdentityTrait;
use app\exception\PaymentException;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentUncertainException;
use support\Request;
use support\Response;

/**
 * 嘉联支付 API 插件。
 *
 * 提供支付宝、微信和银联的扫码、JSAPI、付款码、查单、关单、支付通知与退款能力，
 * 并按终端场景映射到嘉联对应的开放接口产品。
 */
class JlpayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface, PaymentIdentityRequirementInterface
{
    use DirectPaymentProductSelectorTrait;
    use DirectPaymentIdentityTrait;

    private const PRODUCT_MICROPAY = 'micropay';
    private const PRODUCT_OPEN_TRANS_WAPH5PAY = 'open/trans/waph5pay';
    private const PRODUCT_OPEN_TRANS_OFFICIALPAY = 'open/trans/officialpay';
    private const PRODUCT_OPEN_TRANS_UNIONJSPAY = 'open/trans/unionjspay';
    private const PRODUCT_QRCODEPAY = 'qrcodepay';

    private ?JlpayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'jlpay_api',
        'name' => '嘉联支付API',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'link' => 'https://www.jlpay.com/',
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
            ['type' => 'input', 'field' => 'app_id', 'title' => '应用APPID', 'value' => '', 'validate' => [['required' => true, 'message' => '应用APPID不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '嘉联公钥', 'value' => '', 'validate' => [['required' => true, 'message' => '嘉联公钥不能为空']]],
            ['type' => 'input', 'field' => 'mch_id', 'title' => '商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '商户号不能为空']]],
            ['type' => 'input', 'field' => 'term_no', 'title' => '终端号', 'value' => '', 'validate' => [['required' => true, 'message' => '终端号不能为空']]],
            ['type' => 'switch', 'field' => 'is_test', 'title' => '测试环境', 'value' => false],
            $this->directPaymentEnabledProductsField([
                self::PRODUCT_MICROPAY => '付款码支付',
                self::PRODUCT_OPEN_TRANS_WAPH5PAY => '支付宝 UID/JSAPI',
                self::PRODUCT_OPEN_TRANS_OFFICIALPAY => '微信 JSAPI',
                self::PRODUCT_OPEN_TRANS_UNIONJSPAY => '银联 JSAPI（身份链路待核验，不参与当前选择）',
                self::PRODUCT_QRCODEPAY => '扫码支付',
            ]),
            ...$this->directPaymentIdentityConfigFields(['wechat_oauth', 'alipay_oauth']),
        ];
    }

    /**
     * 发起支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    public function pay(array $order): array
    {
        return $this->executeDirectPaymentProduct($order, $this->paymentProducts($order), '嘉联支付');
    }

    /**
     * 为公众号/支付宝 UID 产品声明身份，付款码和扫码不触发授权。
     *
     * officialpay 当前按公众号能力注册，不用 mini_openid 填公众号 open_id；
     * 银联 userAuth/userId 换取及客户端环境未核验，不放开通用 JSAPI 环境限制。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>|null 身份需求
     */
    public function identityRequirement(array $order): ?array
    {
        $product = $this->directPaymentSelection($order, $this->paymentProducts($order))['product'];

        return match ($product) {
            self::PRODUCT_OPEN_TRANS_OFFICIALPAY => $this->directPaymentIdentityRequirement($order, $product, 'wechat_oauth'),
            self::PRODUCT_OPEN_TRANS_WAPH5PAY => $this->directPaymentIdentityRequirement($order, $product, 'alipay_oauth'),
            default => null,
        };
    }

    /**
     * 声明插件支持的产品，由身份预检和真实下单共同消费。
     *
     * @param array<string, mixed> $order 处理器消费的标准下单参数
     * @return array<string, array<string, mixed>> 产品与请求处理器，不执行上游请求
     */
    private function paymentProducts(array $order): array
    {
        return [
            'auth_code' => [
                'products' => [
                    'alipay' => self::PRODUCT_MICROPAY,
                    'wxpay' => self::PRODUCT_MICROPAY,
                    'bank' => self::PRODUCT_MICROPAY,
                ],
                'handler' => fn (): array => $this->scanPay($order),
            ],

            'jsapi' => [
                'products' => [
                    'alipay' => self::PRODUCT_OPEN_TRANS_WAPH5PAY,
                    'wxpay' => self::PRODUCT_OPEN_TRANS_OFFICIALPAY,
                    'bank' => self::PRODUCT_OPEN_TRANS_UNIONJSPAY,
                ],
                'handler' => fn (): array => $this->jsapiPay($order),
            ],

            'qrcode' => [
                'products' => [
                    'alipay' => self::PRODUCT_QRCODEPAY,
                    'wxpay' => self::PRODUCT_QRCODEPAY,
                    'bank' => self::PRODUCT_QRCODEPAY,
                ],
                'handler' => fn (): array => $this->qrcodePay($order),
            ],
        ];
    }

    /**
     * 二维码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function qrcodePay(array $order): array
    {
        $payType = $this->channelPayType((string) $order['pay_type_code']);
        try {
            $data = $this->client()->execute('/open/trans/qrcodepay', $this->basePayload($order) + [
                'pay_type' => $payType,
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentException('嘉联支付下单失败：' . $e->getMessage(), 40200);
        }

        return $this->payResult('qrcode', (string) $order['pay_type_code'], 'qrcodepay', 'qrcodepay', ['qrcode' => (string) ($data['code_url'] ?? ''), 'raw' => $data], $data, $order);
    }

    /**
     * 查询订单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed> 标准支付状态结果
     */
    public function query(array $order): array
    {
        try {
            $data = $this->client()->execute('/open/trans/chnquery', [
                'mch_id' => $this->configText('mch_id'),
                'transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentUncertainException('嘉联支付查单失败：' . $e->getMessage(), 40200);
        }

        $status = (string) ($data['status'] ?? '') === '2' ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING;
        $responsePayNo = trim((string) ($data['out_trade_no'] ?? ''));
        return [
            'status' => $status,
            'pay_no' => $responsePayNo,
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->integerCents($data['total_fee'] ?? null, '嘉联支付查单金额')
                : null,
            'chan_order_no' => $responsePayNo,
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
            'channel_status' => (string) ($data['status'] ?? ''),
        ];
    }

    /**
     * 关闭订单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed> 标准关单结果
     */
    public function close(array $order): array
    {
        try {
            $data = $this->client()->execute('/open/trans/cancel', [
                'mch_id' => $this->configText('mch_id'),
                'out_trade_no' => date('YmdHis') . random_int(1000, 9999),
                'ori_transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
                'mch_create_ip' => (string) ($order['client_ip'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentUncertainException('嘉联支付关单结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => (string) $order['pay_no'],
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '关单成功',
        ];
    }

    /**
     * 申请退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed> 标准退款结果
     */
    public function refund(array $order): array
    {
        try {
            $data = $this->client()->execute('/open/trans/refund', [
                'mch_id' => $this->configText('mch_id'),
                'out_trade_no' => (string) $order['refund_no'],
                'ori_transaction_id' => (string) ($order['chan_trade_no'] ?? ''),
                'total_fee' => (string) (int) $order['refund_amount'],
                'mch_create_ip' => (string) ($order['client_ip'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentUncertainException('嘉联支付退款结果不确定：' . $e->getMessage(), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::SUCCESS,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => (int) ($data['total_fee'] ?? $order['refund_amount']),
            'chan_refund_no' => (string) ($data['transaction_id'] ?? ''),
            'message' => '退款申请成功',
        ];
    }

    /**
     * 解析并验签支付回调。
     *
     * 验签输入包含请求路径、原始请求体与请求头，不能用重新编码后的 JSON 替代原文。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed> 标准支付通知结果
     */
    public function notify(Request $request): array
    {
        $raw = $request->rawBody();
        if (!$this->client()->verifyNotify($request->path(), $raw, $request->header())) {
            throw new PaymentException('嘉联支付回调验签失败', 40200);
        }

        $payload = (array) json_decode($raw, true);
        $success = (string) ($payload['status'] ?? '') === '2';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'pay_no' => trim((string) ($payload['out_trade_no'] ?? '')),
            'paid_amount' => $success ? $this->integerCents($payload['total_fee'] ?? null, '嘉联支付回调金额') : null,
            'message' => (string) ($payload['status'] ?? ''),
            'chan_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'chan_trade_no' => (string) ($payload['transaction_id'] ?? ''),
            'channel_status' => (string) ($payload['status'] ?? ''),
        ];
    }

    /**
     * 返回嘉联成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return json_encode(['ret_code' => '00000'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 返回嘉联失败应答。
     */
    public function notifyFail(): string|Response
    {
        return json_encode(['ret_code' => '00001', 'ret_msg' => 'fail'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 读取渠道整数分金额。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
     */
    private function integerCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^\d+$/', $text) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return (int) $text;
    }

    /**
     * JSAPI 支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function jsapiPay(array $order): array
    {
        $payment = (array) ($order['extra']['payment'] ?? []);
        $payType = (string) $order['pay_type_code'];
        $path = match ($payType) {
            'wxpay' => '/open/trans/officialpay',
            'bank' => '/open/trans/unionjspay',
            default => '/open/trans/waph5pay',
        };
        $payload = $this->basePayload($order) + ['pay_type' => $this->channelPayType($payType)];
        if ($payType === 'bank') {
            $payload['user_id'] = (string) ($payment['unionpay_user_id'] ?? '');
            $payload['app_up_identifier'] = (string) ($payment['app_up_identifier'] ?? '');
            $payload['user_auth_code'] = (string) ($payment['unionpay_auth_code'] ?? '');
            $payload['qr_code'] = (string) $order['return_url'];
        } else {
            $identity = $this->directPaymentIdentityData($order, $payType === 'wxpay' ? 'wechat_oauth' : 'alipay_oauth');
            if ($identity['identity'] === '') {
                throw new PaymentDefinitiveException('嘉联 JSAPI 支付缺少 ' . $identity['identity_field'] . '，请先完成身份授权', 40200);
            }
            if ($payType === 'wxpay') {
                $payload['open_id'] = $identity['identity'];
                $payload['sub_appid'] = $identity['app_id'];
            } else {
                $payload['buyer_id'] = $identity['identity'];
            }
        }

        try {
            $data = $this->client()->execute($path, $payload);
        } catch (JlpaySdkException $e) {
            throw new PaymentException('嘉联JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $payInfo = $data['pay_info'] ?? [];
        if ($payType !== 'bank' && is_string($payInfo)) {
            $decoded = json_decode($payInfo, true);
            $payInfo = is_array($decoded) ? $decoded : ['tradeNO' => $payInfo];
        }

        return $this->payResult($payType === 'bank' ? 'jump' : 'jsapi', $payType, ltrim($path, '/'), ltrim($path, '/'), $payType === 'bank' ? ['url' => (string) $payInfo, 'raw' => $data] : ((array) $payInfo) + ['raw' => $data], $data, $order);
    }

    /**
     * 付款码支付。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付成功结果
     */
    private function scanPay(array $order): array
    {
        try {
            $data = $this->client()->execute('/open/trans/micropay', $this->basePayload($order) + [
                'auth_code' => (string) ($order['extra']['payment']['auth_code'] ?? ''),
            ]);
        } catch (JlpaySdkException $e) {
            throw new PaymentException('嘉联付款码下单失败：' . $e->getMessage(), 40200);
        }

        return $this->successfulPaymentResult($order, [
            'paid_amount' => (int) ($order['amount'] ?? 0),
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => 'micropay',
            'pay_action' => 'micropay',
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ]);
    }

    /**
     * 构造通用下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 渠道下单参数
     */
    private function basePayload(array $order): array
    {
        return [
            'mch_id' => $this->configText('mch_id'),
            'term_no' => $this->configText('term_no'),
            'out_trade_no' => (string) $order['pay_no'],
            'body' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'attach' => (string) $order['subject'],
            'total_fee' => (string) (int) $order['amount'],
            'notify_url' => (string) $order['callback_url'],
            'mch_create_ip' => (string) $order['client_ip'],
        ];
    }

    /**
     * 将平台支付方式映射为嘉联支付类型。
     *
     * @param string $payType 平台支付方式编码
     * @return string 嘉联支付类型
     */
    private function channelPayType(string $payType): string
    {
        return match ($payType) {
            'wxpay' => 'wxpay',
            'bank' => 'unionpay',
            default => 'alipay',
        };
    }

    /**
     * 包装标准支付结果。
     *
     * @param string $page 平台承接页类型
     * @param string $payType 平台支付方式编码
     * @param string $product 嘉联产品编码
     * @param string $action 渠道接口动作
     * @param array<string, mixed> $payParams 承接页参数
     * @param array<string, mixed> $data 上游响应
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed> 标准支付结果
     */
    private function payResult(string $page, string $payType, string $product, string $action, array $payParams, array $data, array $order): array
    {
        return $this->pendingPaymentResult($order, [
            'pay_page' => $page,
            'pay_type' => $payType,
            'pay_product' => $product,
            'pay_action' => $action,
            'pay_params' => $payParams,
            'chan_order_no' => (string) ($data['out_trade_no'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ]);
    }

    /**
     * 获取当前通道的 SDK 客户端。
     *
     * @return JlpayClient
     */
    private function client(): JlpayClient
    {
        if ($this->client === null) {
            $this->client = new JlpayClient([
                'app_id' => $this->configText('app_id'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'is_test' => $this->getConfig('is_test', false) ? '1' : '0',
            ]);
        }

        return $this->client;
    }

    /**
     * 获取字符串配置。
     *
     * @param string $key 配置键
     * @return string 配置值
     */
    private function configText(string $key): string
    {
        return (string) $this->getConfig($key, '');
    }
}
