<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\kuaiqian\KuaiqianClient;
use app\common\sdk\kuaiqian\KuaiqianSdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 快钱支付 API 插件。
 *
 * 第一阶段迁移人民币网关/H5 表单主链路；当面付加密接口、加密退款和双向 SSL 证书链路保留联调项。
 */
class KuaiqianApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private const BANK_GATEWAY = 'https://www.99bill.com/gateway/recvMerchantInfoAction.htm';
    private const MOBILE_GATEWAY = 'https://www.99bill.com/mobilegateway/recvMerchantInfoAction.htm';

    private ?KuaiqianClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'kuaiqian_api',
        'name' => '快钱支付API',
        'author' => 'MPAY',
        'link' => 'https://www.99bill.com/',
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
            ['type' => 'input', 'field' => 'account_id', 'title' => '快钱账户号', 'value' => '', 'validate' => [['required' => true, 'message' => '快钱账户号不能为空']]],
            ['type' => 'password', 'field' => 'merchant_cert_password', 'title' => '商户证书密码', 'value' => '', 'validate' => [['required' => true, 'message' => '商户证书密码不能为空']]],
            ['type' => 'input', 'field' => 'platform_cert_path', 'title' => '快钱公钥证书路径', 'value' => ''],
            ['type' => 'input', 'field' => 'merchant_key_path', 'title' => '商户PFX证书路径', 'value' => ''],
            ['type' => 'input', 'field' => 'sub_account_id', 'title' => '服务商子账户号', 'value' => ''],
            ['type' => 'switch', 'field' => 'own_channel', 'title' => '自有渠道', 'value' => false],
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
        $mobile = in_array((string) ($order['_env'] ?? ''), ['mobile', 'wechat', 'alipay'], true) || $method !== '';

        $url = $mobile ? self::MOBILE_GATEWAY : self::BANK_GATEWAY;
        $payTypeCode = $this->payTypeCode($payType, $method, $mobile);
        $params = [
            'inputCharset' => '1',
            'pageUrl' => (string) $order['return_url'],
            'bgUrl' => (string) $order['callback_url'],
            'version' => $mobile ? 'mobile1.0' : 'v2.0',
            'language' => '1',
            'signType' => '4',
            'merchantAcctId' => $this->configText('account_id') . '01',
            'orderId' => (string) $order['pay_no'],
            'orderAmount' => (string) (int) $order['amount'],
            'orderTime' => date('YmdHis'),
            'productName' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'payType' => $payTypeCode,
            'terminalIp' => (string) $order['client_ip'],
            'tdpformName' => mb_strcut((string) $order['subject'], 0, 32, 'UTF-8'),
        ];
        $aggregate = $this->aggregatePay($payType, $method, (array) ($order['extra']['payment'] ?? []));
        if ($aggregate !== '') {
            $params['aggregatePay'] = $aggregate;
        }
        if ((bool) $this->getConfig('own_channel', false)) {
            $params['extDataType'] = 'NB2';
            $params['extDataContent'] = '<NB2>' . json_encode(['customAuthNetInfo' => ['own_channel' => '1']], JSON_UNESCAPED_UNICODE) . '</NB2>';
        }

        try {
            $html = $this->client()->formHtml($url, $params);
        } catch (KuaiqianSdkException $e) {
            throw new PaymentException('快钱下单签名失败：' . $e->getMessage(), 40200);
        }

        return [
            'pay_page' => 'html',
            'pay_type' => $payType,
            'pay_product' => $payTypeCode,
            'pay_action' => $mobile ? 'mobilegateway' : 'gateway',
            'pay_params' => ['html' => $html, 'raw' => ['gateway' => $url, 'payType' => $payTypeCode]],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 快钱当前实现暂不支持主动查单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return ['success' => false, 'status' => PaymentPluginStatusConstant::PENDING, 'msg' => '快钱插件暂不支持主动查单'];
    }

    /**
     * 快钱当前实现暂不支持关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '快钱插件暂不支持关单'];
    }

    /**
     * 快钱加密退款接口需证书联调，本阶段先明确返回不可用。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        return ['success' => false, 'msg' => '快钱退款依赖加密接口和双向证书，待证书联调后启用'];
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
            throw new PaymentException('快钱回调验签失败', 40200);
        }

        $success = (string) ($payload['payResult'] ?? '') === '10';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['payResult'] ?? ''),
            'channel_order_no' => (string) ($payload['orderId'] ?? ''),
            'channel_trade_no' => (string) ($payload['dealId'] ?? ''),
            'channel_status' => (string) ($payload['payResult'] ?? ''),
        ];
    }

    /**
     * 返回快钱成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return '<result>1</result>';
    }

    /**
     * 返回快钱失败应答。
     */
    public function notifyFail(): string|Response
    {
        return '<result>0</result>';
    }

    /**
     * 解析快钱支付产品编码。
     */
    private function payTypeCode(string $payType, string $method, bool $mobile): string
    {
        if ($payType === 'alipay') {
            return $mobile ? '27-3' : '21';
        }
        if ($payType === 'wxpay') {
            return $method === 'jsapi' ? '26-1' : '26-2';
        }
        if ($mobile) {
            return $method === 'quick' ? '21' : '00';
        }

        return '10';
    }

    /**
     * 构造微信 JSAPI 聚合参数。
     *
     * @param array<string, mixed> $payment 支付载体参数
     */
    private function aggregatePay(string $payType, string $method, array $payment): string
    {
        if ($payType !== 'wxpay' || $method !== 'jsapi') {
            return '';
        }

        return 'appId=' . (string) ($payment['sub_appid'] ?? '') . ',openId=' . (string) ($payment['sub_openid'] ?? '') . ',limitPay=0';
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): KuaiqianClient
    {
        if ($this->client === null) {
            $base = base_path(false) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'sdk' . DIRECTORY_SEPARATOR . 'kuaiqian' . DIRECTORY_SEPARATOR . 'cert';
            $this->client = new KuaiqianClient([
                'merchant_cert_password' => $this->configText('merchant_cert_password'),
                'platform_cert_path' => $this->configText('platform_cert_path') ?: $base . DIRECTORY_SEPARATOR . 'cert.cer',
                'merchant_key_path' => $this->configText('merchant_key_path') ?: $base . DIRECTORY_SEPARATOR . 'key.pfx',
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
