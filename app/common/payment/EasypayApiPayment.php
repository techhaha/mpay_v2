<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\easypay\EasypayClient;
use app\common\sdk\easypay\EasypaySdkException;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 易生易企通支付 API 插件。
 */
class EasypayApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?EasypayClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'easypay_api',
        'name' => '易生易企通支付API',
        'author' => 'MPAY',
        'link' => 'https://www.easypay.com.cn/',
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
            ['type' => 'radio', 'field' => 'req_type', 'title' => '接入模式', 'value' => '2', 'options' => [['label' => '机构模式', 'value' => '2'], ['label' => '商户模式', 'value' => '1']]],
            ['type' => 'input', 'field' => 'req_id', 'title' => '机构号/商户号', 'value' => '', 'validate' => [['required' => true, 'message' => '机构号/商户号不能为空']]],
            ['type' => 'input', 'field' => 'sub_merchant_no', 'title' => '子商户号', 'value' => ''],
            ['type' => 'textarea', 'field' => 'platform_public_key', 'title' => '易生公钥', 'value' => '', 'props' => ['rows' => 4], 'validate' => [['required' => true, 'message' => '易生公钥不能为空']]],
            ['type' => 'textarea', 'field' => 'merchant_private_key', 'title' => '商户私钥', 'value' => '', 'props' => ['rows' => 5], 'validate' => [['required' => true, 'message' => '商户私钥不能为空']]],
            ['type' => 'switch', 'field' => 'sandbox', 'title' => '测试环境', 'value' => false],
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
        if ($method === 'jsapi') {
            return $this->jsapiPay($order);
        }

        $channelPayType = match ($payType) {
            'wxpay' => 'WeChatNative',
            'bank' => 'UnionPayNative',
            default => 'AliPayNative',
        };

        try {
            $data = $this->client()->execute('/trade/native', $this->tradePayload($order, $channelPayType));
        } catch (EasypaySdkException $e) {
            throw new PaymentException('易生下单失败：' . $e->getMessage(), 40200);
        }

        $this->ensureTradeAccepted($data);
        $qrcode = (string) ($data['respOrderInfo']['qrCode'] ?? '');
        if ($qrcode === '') {
            throw new PaymentException('易生未返回二维码链接', 40200, ['response' => $data]);
        }

        return $this->payResult('qrcode', $payType, $channelPayType, 'trade.native', ['qrcode' => $qrcode, 'raw' => $data], $data, $order);
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
            $data = $this->client()->execute('/trade/tradeQuery', [
                'reqInfo' => ['mchtCode' => $this->mchtCode()],
                'reqOrderInfo' => [
                    'orgTrace' => date('YmdHis') . random_int(100000, 999999),
                    'oriOrgTrace' => (string) $order['pay_no'],
                    'oriTransDate' => substr((string) $order['pay_no'], 3, 8),
                ],
                'payInfo' => ['transDate' => date('Ymd')],
            ]);
        } catch (EasypaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        $state = (string) ($data['respStateInfo']['transState'] ?? '');
        return [
            'success' => true,
            'status' => in_array($state, ['0', '1'], true) ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'channel_order_no' => (string) ($data['respOrderInfo']['orgTrace'] ?? $order['pay_no']),
            'channel_trade_no' => (string) ($data['respOrderInfo']['outTrace'] ?? $order['chan_trade_no'] ?? ''),
            'channel_status' => $state,
            'message' => (string) ($data['respStateInfo']['transStatusDesc'] ?? ''),
            'raw_data' => $data,
        ];
    }

    /**
     * 易生旧插件未提供关单链路。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return ['success' => false, 'msg' => '易生插件暂不支持关单'];
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
            $data = $this->client()->execute('/trade/refund/apply', [
                'reqInfo' => ['mchtCode' => $this->mchtCode()],
                'reqOrderInfo' => [
                    'orgTrace' => (string) $order['refund_no'],
                    'oriOrgTrace' => (string) $order['pay_no'],
                    'oriTransDate' => substr((string) $order['pay_no'], 3, 8),
                    'refundAmount' => (int) $order['refund_amount'],
                ],
                'payInfo' => ['transDate' => date('Ymd')],
            ]);
        } catch (EasypaySdkException $e) {
            return ['success' => false, 'msg' => $e->getMessage()];
        }

        return [
            'success' => true,
            'msg' => '退款申请成功',
            'chan_refund_no' => (string) ($data['outTrace'] ?? $order['refund_no']),
            'refund_amount' => (int) ($data['transAmt'] ?? $order['refund_amount']),
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
        if (!$this->client()->verify((array) ($payload['reqHeader'] ?? []), (array) ($payload['reqBody'] ?? []), (string) ($payload['reqSign'] ?? ''))) {
            throw new PaymentException('易生回调验签失败', 40200);
        }

        $body = (array) ($payload['reqBody'] ?? []);
        $state = (string) ($body['respStateInfo']['transState'] ?? '');
        $success = in_array($state, ['0', '1'], true);

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::PENDING,
            'message' => (string) ($body['respStateInfo']['transStatusDesc'] ?? ''),
            'channel_order_no' => (string) ($body['respOrderInfo']['orgTrace'] ?? ''),
            'channel_trade_no' => (string) ($body['respOrderInfo']['outTrace'] ?? ''),
            'channel_status' => $state,
        ];
    }

    /**
     * 返回易生成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return json_encode(['code' => '000000', 'msg' => 'Success'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 返回易生失败应答。
     */
    public function notifyFail(): string|Response
    {
        return json_encode(['code' => '100001', 'msg' => 'sign error'], JSON_UNESCAPED_UNICODE);
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
        $channelPayType = match ($payType) {
            'wxpay' => 'WeChatJsapi',
            'bank' => 'UnionPayJsapi',
            default => 'AliPayJsapi',
        };
        $payload = $this->tradePayload($order, $channelPayType);
        if ($payType === 'wxpay') {
            $payload['wxBizParam'] = ['subAppid' => (string) ($payment['sub_appid'] ?? ''), 'subOpenId' => (string) ($payment['sub_openid'] ?? '')];
        } elseif ($payType === 'bank') {
            $payload['qrBizParam'] = ['userId' => (string) ($payment['sub_openid'] ?? ''), 'transType' => '10', 'areaInfo' => '1561000'];
        } else {
            $payload['aliBizParam'] = ['buyerId' => (string) ($payment['sub_openid'] ?? $payment['buyer_id'] ?? '')];
        }

        try {
            $data = $this->client()->execute('/trade/jsapi', $payload);
        } catch (EasypaySdkException $e) {
            throw new PaymentException('易生JSAPI下单失败：' . $e->getMessage(), 40200);
        }

        $this->ensureTradeAccepted($data);
        if ($payType === 'bank') {
            return $this->payResult('jump', $payType, $channelPayType, 'trade.jsapi', ['url' => (string) ($data['qrRespParamInfo']['qrRedirectUrl'] ?? ''), 'raw' => $data], $data, $order);
        }

        $params = $payType === 'wxpay'
            ? ((array) json_decode((string) ($data['wxRespParamInfo']['wcPayData'] ?? ''), true))
            : ['tradeNO' => (string) ($data['aliRespParamInfo']['tradeNo'] ?? '')];
        $params['raw'] = $data;

        return $this->payResult('jsapi', $payType, $channelPayType, 'trade.jsapi', $params, $data, $order);
    }

    /**
     * 构造交易请求参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function tradePayload(array $order, string $channelPayType): array
    {
        $payload = [
            'reqInfo' => ['mchtCode' => $this->mchtCode()],
            'reqOrderInfo' => [
                'orgTrace' => (string) $order['pay_no'],
                'transAmount' => (int) $order['amount'],
                'orderSub' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
                'backUrl' => (string) $order['callback_url'],
            ],
            'payInfo' => ['payType' => $channelPayType, 'transDate' => date('Ymd')],
            'settleParamInfo' => ['delaySettleFlag' => '0', 'patnerSettleFlag' => '0', 'splitSettleFlag' => '0'],
            'riskData' => ['customerIp' => (string) $order['client_ip']],
        ];
        if (str_starts_with($channelPayType, 'UnionPay')) {
            $payload['qrBizParam'] = ['transType' => '10', 'areaInfo' => '1561000'];
        }

        return $payload;
    }

    /**
     * 校验交易响应是否可继续承接。
     *
     * @param array<string, mixed> $data 上游响应
     */
    private function ensureTradeAccepted(array $data): void
    {
        $state = (string) ($data['respStateInfo']['transState'] ?? '');
        if ($state === 'X') {
            throw new PaymentException((string) ($data['respStateInfo']['appendRetMsg'] ?? $data['respStateInfo']['transStatusDesc'] ?? '易生交易失败'), 40200);
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
            'chan_order_no' => (string) ($data['respOrderInfo']['orgTrace'] ?? $order['pay_no']),
            'chan_trade_no' => (string) ($data['respOrderInfo']['outTrace'] ?? ''),
        ];
    }

    /**
     * 当前收款商户号。
     */
    private function mchtCode(): string
    {
        return $this->configText('req_type') === '2'
            ? $this->configText('sub_merchant_no')
            : $this->configText('req_id');
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): EasypayClient
    {
        if ($this->client === null) {
            $this->client = new EasypayClient([
                'req_id' => $this->configText('req_id'),
                'req_type' => $this->configText('req_type'),
                'platform_public_key' => $this->configText('platform_public_key'),
                'merchant_private_key' => $this->configText('merchant_private_key'),
                'sandbox' => (bool) $this->getConfig('sandbox', false),
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
