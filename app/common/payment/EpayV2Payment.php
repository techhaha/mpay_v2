<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\AuthConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use app\service\payment\epay\EpaySignerManager;
use support\Request;
use support\Response;

/**
 * ePay V2 网关插件。
 *
 * 适用于对接已升级为 V2 协议的第三方平台。
 */
class EpayV2Payment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?EpaySignerManager $epaySignerManager = null;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'epay_v2',
        'name' => 'ePay V2 网关',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'gateway_url',
                'title' => '上游网关地址',
                'value' => '',
                'props' => [
                    'placeholder' => '例如：https://pay.example.com',
                ],
                'validate' => [
                    ['required' => true, 'message' => '上游网关地址不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'upstream_pid',
                'title' => '上游商户ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入第三方平台分配的 pid',
                ],
                'validate' => [
                    ['required' => true, 'message' => '上游商户ID不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '上游商户私钥',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入对接上游 V2 的商户 RSA 私钥',
                    'rows' => 6,
                ],
                'validate' => [
                    ['required' => true, 'message' => '上游商户私钥不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '上游平台公钥',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入上游平台 RSA 公钥',
                    'rows' => 6,
                ],
                'validate' => [
                    ['required' => true, 'message' => '上游平台公钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'create_path',
                'title' => '下单路径',
                'value' => '/api/pay/create',
                'props' => [
                    'placeholder' => '默认 /api/pay/create',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'query_path',
                'title' => '查单路径',
                'value' => '/api/pay/query',
                'props' => [
                    'placeholder' => '默认 /api/pay/query',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'refund_path',
                'title' => '退款路径',
                'value' => '/api/pay/refund',
                'props' => [
                    'placeholder' => '默认 /api/pay/refund',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'close_path',
                'title' => '关单路径',
                'value' => '/api/pay/close',
                'props' => [
                    'placeholder' => '默认 /api/pay/close',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'type_mapping_json',
                'title' => '支付方式映射',
                'value' => "{\n  \"alipay\": \"alipay\",\n  \"wxpay\": \"wxpay\",\n  \"unionpay\": \"bank\"\n}",
                'props' => [
                    'placeholder' => 'JSON 格式，例如 {\"unionpay\":\"bank\"}',
                    'rows' => 6,
                ],
            ],
        ],
    ];

    public function init(array $channelConfig): void
    {
        parent::init($channelConfig);
    }

    public function pay(array $order): array
    {
        $payload = [
            'pid' => $this->requireConfigValue('upstream_pid', '上游商户ID'),
            'method' => $this->resolveV2Method($order),
            'type' => $this->resolveUpstreamType($order, [
                'alipay' => 'alipay',
                'wxpay' => 'wxpay',
                'unionpay' => 'bank',
            ]),
            'out_trade_no' => $this->resolveOrderNo($order),
            'notify_url' => trim((string) ($order['callback_url'] ?? '')),
            'name' => $this->resolveSubject($order),
            'money' => $this->amountToMoney($this->resolveAmount($order)),
            'timestamp' => (string) time(),
            'clientip' => trim((string) ($order['client_ip'] ?? '127.0.0.1')),
        ];

        $returnUrl = trim((string) ($order['return_url'] ?? ''));
        if ($returnUrl !== '') {
            $payload['return_url'] = $returnUrl;
        }

        $device = $this->resolveDevice($order);
        if ($device !== '') {
            $payload['device'] = $device;
        }

        $param = $this->resolveParamValue($order);
        if ($param !== '') {
            $payload['param'] = $param;
        }

        $context = $this->resolveExtraContext($order);
        foreach (['auth_code', 'sub_openid', 'sub_appid'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $payload = $this->signPayload($payload, AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA, $this->requireConfigValue('merchant_private_key', '上游商户私钥'));
        $response = $this->isMockEnabled()
            ? $this->buildMockPayResponse($payload, $order)
            : $this->requestFormJson('POST', $this->resolveGatewayUrl('create_path', '/api/pay/create'), $payload);
        $this->verifyPayloadSignature(
            $response,
            AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            $this->requireConfigValue('platform_public_key', '上游平台公钥'),
            '上游 V2 下单响应验签失败'
        );

        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new PaymentException((string) ($response['msg'] ?? '上游 V2 下单失败'), 40200, [
                'response' => $response,
            ]);
        }

        $channelNos = $this->resolveChannelNos($response + [
            'trade_no' => (string) ($response['trade_no'] ?? $payload['out_trade_no']),
        ]);
        $payType = strtolower(trim((string) ($response['pay_type'] ?? '')));
        $payParams = $this->normalizePayResponse($payType, $response['pay_info'] ?? null);

        return [
            'pay_product' => (string) $payload['type'],
            'pay_action' => (string) ($payParams['type'] ?? $payType),
            'pay_params' => $payParams,
            'chan_order_no' => $channelNos['channel_order_no'],
            'chan_trade_no' => $channelNos['channel_trade_no'],
        ];
    }

    public function query(array $order): array
    {
        $payload = [
            'pid' => $this->requireConfigValue('upstream_pid', '上游商户ID'),
            'timestamp' => (string) time(),
        ];

        $tradeNo = trim((string) ($order['chan_order_no'] ?? $order['chan_trade_no'] ?? ''));
        if ($tradeNo !== '') {
            $payload['trade_no'] = $tradeNo;
        } else {
            $payload['out_trade_no'] = $this->resolveOrderNo($order);
        }

        $payload = $this->signPayload($payload, AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA, $this->requireConfigValue('merchant_private_key', '上游商户私钥'));
        $response = $this->isMockEnabled()
            ? $this->buildMockQueryResponse($order)
            : $this->requestFormJson('POST', $this->resolveGatewayUrl('query_path', '/api/pay/query'), $payload);
        $this->verifyPayloadSignature(
            $response,
            AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            $this->requireConfigValue('platform_public_key', '上游平台公钥'),
            '上游 V2 查单响应验签失败'
        );

        if ((int) ($response['code'] ?? -1) !== 0) {
            return [
                'success' => false,
                'msg' => (string) ($response['msg'] ?? '上游 V2 查单失败'),
                'raw_data' => $response,
            ];
        }

        $channelNos = $this->resolveChannelNos($response);
        $statusCode = (int) ($response['status'] ?? 0);
        $status = match ($statusCode) {
            1, 2 => PaymentPluginStatusConstant::SUCCESS,
            default => PaymentPluginStatusConstant::PENDING,
        };

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => $channelNos['channel_order_no'],
            'channel_trade_no' => $channelNos['channel_trade_no'],
            'channel_status' => (string) $statusCode,
            'paid_at' => $response['endtime'] ?? null,
            'ext_json' => [
                'refundmoney' => (string) ($response['refundmoney'] ?? ''),
                'channel_response' => $response,
            ],
        ];
    }

    public function close(array $order): array
    {
        $payload = [
            'pid' => $this->requireConfigValue('upstream_pid', '上游商户ID'),
            'timestamp' => (string) time(),
        ];

        $tradeNo = trim((string) ($order['chan_order_no'] ?? $order['chan_trade_no'] ?? ''));
        if ($tradeNo !== '') {
            $payload['trade_no'] = $tradeNo;
        } else {
            $payload['out_trade_no'] = $this->resolveOrderNo($order);
        }

        $payload = $this->signPayload($payload, AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA, $this->requireConfigValue('merchant_private_key', '上游商户私钥'));
        $response = $this->isMockEnabled()
            ? $this->buildMockCloseResponse($order)
            : $this->requestFormJson('POST', $this->resolveGatewayUrl('close_path', '/api/pay/close'), $payload);
        $this->verifyPayloadSignature(
            $response,
            AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            $this->requireConfigValue('platform_public_key', '上游平台公钥'),
            '上游 V2 关单响应验签失败'
        );

        return [
            'success' => (int) ($response['code'] ?? -1) === 0,
            'msg' => (string) ($response['msg'] ?? ''),
            'raw_data' => $response,
        ];
    }

    public function refund(array $order): array
    {
        $payload = [
            'pid' => $this->requireConfigValue('upstream_pid', '上游商户ID'),
            'money' => $this->amountToMoney((int) ($order['refund_amount'] ?? 0)),
            'timestamp' => (string) time(),
        ];

        $tradeNo = trim((string) ($order['chan_order_no'] ?? $order['chan_trade_no'] ?? ''));
        if ($tradeNo !== '') {
            $payload['trade_no'] = $tradeNo;
        } else {
            $payload['out_trade_no'] = $this->resolveOrderNo($order);
        }

        $outRefundNo = trim((string) ($order['refund_no'] ?? ''));
        if ($outRefundNo !== '') {
            $payload['out_refund_no'] = $outRefundNo;
        }

        $payload = $this->signPayload($payload, AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA, $this->requireConfigValue('merchant_private_key', '上游商户私钥'));
        $response = $this->isMockEnabled()
            ? $this->buildMockRefundResponse($order)
            : $this->requestFormJson('POST', $this->resolveGatewayUrl('refund_path', '/api/pay/refund'), $payload);
        $this->verifyPayloadSignature(
            $response,
            AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            $this->requireConfigValue('platform_public_key', '上游平台公钥'),
            '上游 V2 退款响应验签失败'
        );

        return [
            'success' => (int) ($response['code'] ?? -1) === 0,
            'msg' => (string) ($response['msg'] ?? ''),
            'chan_refund_no' => trim((string) ($response['refund_no'] ?? $response['out_refund_no'] ?? '')),
            'raw_data' => $response,
        ];
    }

    public function notify(Request $request): array
    {
        $payload = $this->resolveNotifyPayload($request);
        $this->verifyPayloadSignature(
            $payload,
            AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            $this->requireConfigValue('platform_public_key', '上游平台公钥'),
            '上游 V2 回调验签失败'
        );

        $channelNos = $this->resolveChannelNos($payload);
        $status = $this->normalizeNotifyStatus((string) ($payload['trade_status'] ?? ''));

        return [
            'status' => $status,
            'message' => (string) ($payload['trade_status'] ?? ''),
            'channel_order_no' => $channelNos['channel_order_no'],
            'channel_trade_no' => $channelNos['channel_trade_no'],
            'channel_status' => (string) ($payload['trade_status'] ?? ''),
            'paid_at' => $payload['endtime'] ?? null,
            'ext_json' => [
                'channel_type' => (string) ($payload['type'] ?? ''),
                'timestamp' => (string) ($payload['timestamp'] ?? ''),
            ],
        ];
    }

    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 获取签名管理器。
     */
    private function signerManager(): EpaySignerManager
    {
        if ($this->epaySignerManager === null) {
            /** @var EpaySignerManager $manager */
            $manager = container_make(EpaySignerManager::class, []);
            $this->epaySignerManager = $manager;
        }

        return $this->epaySignerManager;
    }

    /**
     * 是否启用插件内置 mock。
     */
    private function isMockEnabled(): bool
    {
        $value = $this->getConfig('mock_enabled', false);
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $filtered ?? false;
    }

    /**
     * 读取必填配置。
     */
    private function requireConfigValue(string $key, string $label): string
    {
        $value = trim((string) $this->getConfig($key, ''));
        if ($value === '') {
            throw new PaymentException($label . '未配置', 40200, [
                'config_key' => $key,
            ]);
        }

        return $value;
    }

    /**
     * 构建上游接口地址。
     */
    private function resolveGatewayUrl(string $pathConfigKey, string $defaultPath): string
    {
        $baseUrl = rtrim($this->requireConfigValue('gateway_url', '上游网关地址'), '/');
        $path = trim((string) $this->getConfig($pathConfigKey, $defaultPath));
        if ($path === '') {
            $path = $defaultPath;
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return rtrim($path, '/');
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestFormJson(string $method, string $url, array $payload): array
    {
        $response = $this->request($method, $url, [
            'form_params' => $payload,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        return $this->decodeJsonResponse((string) $response->getBody(), $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(string $body, string $url): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PaymentException('上游网关响应不是合法 JSON', 40200, [
                'url' => $url,
                'body_excerpt' => $this->clipText($body),
            ]);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function signPayload(array $payload, string $signType, string $key): array
    {
        $payload['sign_type'] = $signType;
        $payload['sign'] = $this->signerManager()->sign($payload, $signType, $key);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyPayloadSignature(array $payload, string $defaultSignType, string $key, string $message): void
    {
        $sign = trim((string) ($payload['sign'] ?? ''));
        if ($sign === '') {
            throw new PaymentException($message, 40200, ['reason' => 'missing_sign']);
        }

        $signType = trim((string) ($payload['sign_type'] ?? $defaultSignType));
        if (!$this->signerManager()->verify($payload, $signType, $sign, $key)) {
            throw new PaymentException($message, 40200, [
                'sign_type' => $signType,
            ]);
        }
    }

    /**
     * @param array<string, string> $defaultMapping
     * @return array<string, string>
     */
    private function resolveTypeMapping(array $defaultMapping): array
    {
        $raw = $this->getConfig('type_mapping_json', '');
        $mapping = $defaultMapping;

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                $source = strtolower(trim((string) $key));
                $target = strtolower(trim((string) $value));
                if ($source !== '' && $target !== '') {
                    $mapping[$source] = $target;
                }
            }

            return $mapping;
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return $mapping;
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new PaymentException('支付方式映射配置不是合法 JSON', 40200, [
                'config_key' => 'type_mapping_json',
            ]);
        }

        foreach ($decoded as $key => $value) {
            $source = strtolower(trim((string) $key));
            $target = strtolower(trim((string) $value));
            if ($source !== '' && $target !== '') {
                $mapping[$source] = $target;
            }
        }

        return $mapping;
    }

    /**
     * @param array<string, string> $defaultMapping
     */
    private function resolveUpstreamType(array $order, array $defaultMapping): string
    {
        $payTypeCode = strtolower(trim((string) ($order['pay_type_code'] ?? '')));
        if ($payTypeCode === '') {
            throw new PaymentException('订单缺少支付方式编码', 40200);
        }

        $mapping = $this->resolveTypeMapping($defaultMapping);
        $upstreamType = strtolower(trim((string) ($mapping[$payTypeCode] ?? '')));
        if ($upstreamType === '') {
            throw new PaymentException('未配置上游支付方式映射', 40200, [
                'pay_type_code' => $payTypeCode,
            ]);
        }

        return $upstreamType;
    }

    /**
     * 获取平台内部支付单号，作为上游商户订单号。
     */
    private function resolveOrderNo(array $order): string
    {
        $orderNo = trim((string) ($order['order_id'] ?? $order['pay_no'] ?? $order['out_trade_no'] ?? ''));
        if ($orderNo === '') {
            throw new PaymentException('订单缺少订单号', 40200);
        }

        return $orderNo;
    }

    /**
     * 获取支付金额（分）。
     */
    private function resolveAmount(array $order): int
    {
        $amount = (int) ($order['amount'] ?? $order['pay_amount'] ?? 0);
        if ($amount <= 0) {
            throw new PaymentException('订单金额不合法', 40200);
        }

        return $amount;
    }

    /**
     * 获取订单标题。
     */
    private function resolveSubject(array $order): string
    {
        $subject = trim((string) ($order['subject'] ?? $order['body'] ?? ''));
        if ($subject === '') {
            throw new PaymentException('订单标题不能为空', 40200);
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($subject, 0, 127, 'UTF-8');
        }

        return substr($subject, 0, 127);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveExtraContext(array $order): array
    {
        $context = [];
        foreach ([$order['extra'] ?? null, $order['param'] ?? null] as $bag) {
            if (is_array($bag)) {
                $context = array_merge($context, $bag);
                foreach (['merchant', 'payment', 'source'] as $section) {
                    if (isset($bag[$section]) && is_array($bag[$section])) {
                        $context = array_merge($context, $bag[$section]);
                    }
                }
                continue;
            }

            if (!is_string($bag)) {
                continue;
            }

            $text = trim($bag);
            if ($text === '') {
                continue;
            }

            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $context = array_merge($context, $decoded);
                continue;
            }

            parse_str($text, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                $context = array_merge($context, $parsed);
            }
        }

        return $context;
    }

    /**
     * 归一化备注透传字段。
     */
    private function resolveParamValue(array $order): string
    {
        $context = $this->resolveExtraContext($order);
        $param = $context['param'] ?? null;
        if ($param === null || $param === '') {
            return '';
        }

        if (is_scalar($param)) {
            return trim((string) $param);
        }

        $json = json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '';
    }

    /**
     * 归一化客户端环境。
     */
    private function resolveDevice(array $order, string $default = 'pc'): string
    {
        $device = strtolower(trim((string) ($order['_env'] ?? $order['device'] ?? '')));
        return $device !== '' ? $device : $default;
    }

    /**
     * 解析 V2 上游 method。
     */
    private function resolveV2Method(array $order): string
    {
        $context = $this->resolveExtraContext($order);
        $method = strtolower(trim((string) ($context['method'] ?? '')));
        $allowed = ['web', 'jump', 'jsapi', 'app', 'scan', 'applet'];
        if (in_array($method, $allowed, true)) {
            return $method;
        }

        if (trim((string) ($context['auth_code'] ?? '')) !== '') {
            return 'scan';
        }

        if (trim((string) ($context['sub_openid'] ?? '')) !== '') {
            return 'jsapi';
        }

        return match ($this->resolveDevice($order)) {
            'wechat' => 'jsapi',
            'mobile', 'qq', 'alipay' => 'jump',
            default => 'web',
        };
    }

    /**
     * 提取回调入参。
     *
     * @return array<string, mixed>
     */
    private function resolveNotifyPayload(Request $request): array
    {
        $payload = array_merge((array) $request->get(), (array) $request->post());
        if ($payload !== []) {
            return $payload;
        }

        $all = $request->all();

        return is_array($all) ? $all : [];
    }

    /**
     * 将分转换为元字符串。
     */
    private function amountToMoney(int $amount): string
    {
        return FormatHelper::amount($amount);
    }

    /**
     * @return array{channel_order_no: string, channel_trade_no: string}
     */
    private function resolveChannelNos(array $payload): array
    {
        $channelOrderNo = trim((string) ($payload['trade_no'] ?? $payload['transaction_id'] ?? ''));
        $channelTradeNo = trim((string) ($payload['api_trade_no'] ?? $payload['channel_trade_no'] ?? ''));

        if ($channelOrderNo === '' && $channelTradeNo === '') {
            throw new PaymentException('上游返回缺少渠道订单号', 40200);
        }

        if ($channelOrderNo === '') {
            $channelOrderNo = $channelTradeNo;
        }
        if ($channelTradeNo === '') {
            $channelTradeNo = $channelOrderNo;
        }

        return [
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelTradeNo,
        ];
    }

    /**
     * 归一化回调支付状态。
     */
    private function normalizeNotifyStatus(string $tradeStatus): string
    {
        $tradeStatus = strtoupper(trim($tradeStatus));
        if (in_array($tradeStatus, ['TRADE_SUCCESS', 'SUCCESS', 'PAY_SUCCESS', 'FINISHED'], true)) {
            return PaymentPluginStatusConstant::SUCCESS;
        }

        if (in_array($tradeStatus, ['TRADE_FAIL', 'FAILED', 'TRADE_CLOSED', 'CLOSED', 'PAYERROR'], true)) {
            return PaymentPluginStatusConstant::FAILED;
        }

        return PaymentPluginStatusConstant::PENDING;
    }

    /**
     * 生成响应文本摘要。
     */
    private function clipText(string $text, int $length = 240): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        return strlen($text) <= $length ? $text : substr($text, 0, $length) . '...';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function buildMockPayResponse(array $payload, array $order): array
    {
        $seed = strtolower((string) ($payload['out_trade_no'] ?? $this->resolveOrderNo($order)));
        $channelOrderNo = 'V2ORD' . strtoupper(substr(md5($seed), 0, 16));
        $channelTradeNo = 'V2TRD' . strtoupper(substr(sha1($seed), 0, 16));

        return $this->buildMockSignedResponse([
            'code' => 0,
            'msg' => 'success',
            'trade_no' => $channelOrderNo,
            'api_trade_no' => $channelTradeNo,
            'pay_type' => 'jump',
            'pay_info' => [
                'type' => 'jump',
                'payurl' => $this->resolveMockJumpUrl($channelTradeNo),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function buildMockQueryResponse(array $order): array
    {
        $channelOrderNo = trim((string) ($order['chan_order_no'] ?? ''));
        $channelTradeNo = trim((string) ($order['chan_trade_no'] ?? ''));
        if ($channelOrderNo === '' && $channelTradeNo === '') {
            $seed = strtolower($this->resolveOrderNo($order));
            $channelOrderNo = 'V2ORD' . strtoupper(substr(md5($seed), 0, 16));
            $channelTradeNo = 'V2TRD' . strtoupper(substr(sha1($seed), 0, 16));
        } elseif ($channelOrderNo === '') {
            $channelOrderNo = $channelTradeNo;
        } elseif ($channelTradeNo === '') {
            $channelTradeNo = $channelOrderNo;
        }

        return $this->buildMockSignedResponse([
            'code' => 0,
            'msg' => 'success',
            'trade_no' => $channelOrderNo,
            'api_trade_no' => $channelTradeNo,
            'out_trade_no' => $this->resolveOrderNo($order),
            'status' => 1,
            'buyer' => 'MOCK_V2_BUYER',
            'param' => $this->resolveParamValue($order),
            'refundmoney' => '0.00',
            'endtime' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function buildMockCloseResponse(array $order): array
    {
        return $this->buildMockSignedResponse([
            'code' => 0,
            'msg' => 'success',
            'trade_no' => trim((string) ($order['chan_order_no'] ?? '')),
            'api_trade_no' => trim((string) ($order['chan_trade_no'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function buildMockRefundResponse(array $order): array
    {
        $seed = strtolower(trim((string) ($order['refund_no'] ?? $this->resolveOrderNo($order))));

        return $this->buildMockSignedResponse([
            'code' => 0,
            'msg' => 'success',
            'refund_no' => 'V2REF' . strtoupper(substr(md5($seed), 0, 16)),
            'out_refund_no' => trim((string) ($order['refund_no'] ?? '')),
            'trade_no' => trim((string) ($order['chan_order_no'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildMockSignedResponse(array $payload): array
    {
        $payload['timestamp'] = (string) ($payload['timestamp'] ?? time());
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA;

        $signPayload = $payload;
        unset($signPayload['sign'], $signPayload['sign_type']);
        $payload['sign'] = $this->signerManager()->sign(
            $signPayload,
            AuthConstant::API_SIGN_NAME_SHA256_WITH_RSA,
            $this->requireConfigValue('mock_platform_private_key', 'Mock 上游平台私钥')
        );

        return $payload;
    }

    /**
     * 构建 mock 跳转地址。
     */
    private function resolveMockJumpUrl(string $channelTradeNo): string
    {
        $baseUrl = trim((string) $this->getConfig('mock_jump_base_url', 'https://mock.epay.test/pay/v2'));
        if ($baseUrl === '') {
            $baseUrl = 'https://mock.epay.test/pay/v2';
        }

        return rtrim($baseUrl, '/') . '?trade_no=' . rawurlencode($channelTradeNo);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayResponse(string $payType, mixed $payInfo): array
    {
        $payType = strtolower(trim($payType));
        $payload = is_array($payInfo) ? $payInfo : [];
        if (!is_array($payload)) {
            $payload = [];
        }

        if (!is_array($payInfo)) {
            $text = trim((string) $payInfo);
            if ($text !== '') {
                $payload = match ($payType) {
                    'jump' => ['payurl' => $text, 'redirect_url' => $text],
                    'html' => ['html' => $text],
                    'qrcode' => ['qrcode' => $text, 'qrcode_text' => $text],
                    'urlscheme' => ['urlscheme' => $text, 'redirect_url' => $text],
                    default => ['payload' => $text],
                };
            }
        }

        $payload['type'] = $payType !== '' ? $payType : (string) ($payload['type'] ?? '');

        if ($payload['type'] === 'jump') {
            $jumpUrl = trim((string) ($payload['payurl'] ?? $payload['redirect_url'] ?? $payload['url'] ?? ''));
            if ($jumpUrl !== '') {
                $payload['payurl'] = $jumpUrl;
                $payload['redirect_url'] = $jumpUrl;
            }
        }

        if ($payload['type'] === 'qrcode') {
            $qrcode = trim((string) ($payload['qrcode'] ?? $payload['qrcode_text'] ?? $payload['qrcode_url'] ?? ''));
            if ($qrcode !== '') {
                $payload['qrcode'] = $qrcode;
                $payload['qrcode_text'] = $qrcode;
            }
        }

        if ($payload['type'] === 'urlscheme') {
            $urlscheme = trim((string) ($payload['urlscheme'] ?? $payload['redirect_url'] ?? ''));
            if ($urlscheme !== '') {
                $payload['urlscheme'] = $urlscheme;
                $payload['redirect_url'] = $urlscheme;
            }
        }

        return $payload;
    }
}
