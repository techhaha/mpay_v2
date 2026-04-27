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
 * ePay V1 网关插件。
 *
 * 适用于对接仍提供 V1 协议的第三方平台。
 */
class EpayV1Payment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private ?EpaySignerManager $epaySignerManager = null;

    /**
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'epay_v1',
        'name' => 'ePay V1 网关',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
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
                'field' => 'upstream_key',
                'title' => '上游 MD5 密钥',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入第三方平台分配的 API Key / KEY',
                    'rows' => 4,
                ],
                'validate' => [
                    ['required' => true, 'message' => '上游 MD5 密钥不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pay_path',
                'title' => '下单路径',
                'value' => '/mapi.php',
                'props' => [
                    'placeholder' => '默认 /mapi.php',
                ],
            ],
            [
                'type' => 'input',
                'field' => 'api_path',
                'title' => '查询/退款路径',
                'value' => '/api.php',
                'props' => [
                    'placeholder' => '默认 /api.php',
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'type_mapping_json',
                'title' => '支付方式映射',
                'value' => "{\n  \"alipay\": \"alipay\",\n  \"wxpay\": \"wxpay\"\n}",
                'props' => [
                    'placeholder' => 'JSON 格式，例如 {\"wxpay\":\"wxpay\"}',
                    'rows' => 5,
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
            'type' => $this->resolveUpstreamType($order, [
                'alipay' => 'alipay',
                'wxpay' => 'wxpay',
            ]),
            'out_trade_no' => $this->resolveOrderNo($order),
            'notify_url' => trim((string) ($order['callback_url'] ?? '')),
            'return_url' => trim((string) ($order['return_url'] ?? '')),
            'name' => $this->resolveSubject($order),
            'money' => $this->amountToMoney($this->resolveAmount($order)),
            'clientip' => trim((string) ($order['client_ip'] ?? '127.0.0.1')),
            'device' => $this->resolveDevice($order),
        ];

        $param = $this->resolveParamValue($order);
        if ($param !== '') {
            $payload['param'] = $param;
        }

        $payload = $this->signPayload($payload, AuthConstant::API_SIGN_NAME_MD5, $this->requireConfigValue('upstream_key', '上游 MD5 密钥'));
        $response = $this->isMockEnabled()
            ? $this->buildMockPayResponse($payload, $order)
            : $this->requestFormJson('POST', $this->resolveGatewayUrl('pay_path', '/mapi.php'), $payload);

        if ((int) ($response['code'] ?? 0) !== 1) {
            throw new PaymentException((string) ($response['msg'] ?? '上游 V1 下单失败'), 40200, [
                'response' => $response,
            ]);
        }

        $channelNos = $this->resolveChannelNos($response + [
            'trade_no' => (string) ($response['trade_no'] ?? $payload['out_trade_no']),
        ]);
        $payParams = $this->normalizePayResponse($response);

        return [
            'pay_product' => (string) $payload['type'],
            'pay_action' => (string) ($payParams['type'] ?? ''),
            'pay_params' => $payParams,
            'chan_order_no' => $channelNos['channel_order_no'],
            'chan_trade_no' => $channelNos['channel_trade_no'],
        ];
    }

    public function query(array $order): array
    {
        $payload = [
            'act' => 'order',
            'pid' => $this->requireConfigValue('upstream_pid', '上游商户ID'),
            'key' => $this->requireConfigValue('upstream_key', '上游 MD5 密钥'),
        ];

        $tradeNo = trim((string) ($order['chan_order_no'] ?? $order['chan_trade_no'] ?? ''));
        if ($tradeNo !== '') {
            $payload['trade_no'] = $tradeNo;
        } else {
            $payload['out_trade_no'] = $this->resolveOrderNo($order);
        }

        $response = $this->isMockEnabled()
            ? $this->buildMockQueryResponse($order)
            : $this->requestQueryJson($this->resolveGatewayUrl('api_path', '/api.php'), $payload);
        if ((int) ($response['code'] ?? 0) !== 1) {
            return [
                'success' => false,
                'msg' => (string) ($response['msg'] ?? '上游 V1 查单失败'),
                'raw_data' => $response,
            ];
        }

        $channelNos = $this->resolveChannelNos($response);
        $status = (int) ($response['status'] ?? 0) === 1
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => $channelNos['channel_order_no'],
            'channel_trade_no' => $channelNos['channel_trade_no'],
            'channel_status' => (string) ($response['status'] ?? ''),
            'paid_at' => $response['endtime'] ?? null,
            'ext_json' => [
                'channel_response' => $response,
            ],
        ];
    }

    public function close(array $order): array
    {
        throw new PaymentException('上游 ePay V1 协议不支持关单', 40200, [
            'plugin_code' => $this->getCode(),
            'order_no' => $this->resolveOrderNo($order),
        ]);
    }

    public function refund(array $order): array
    {
        $payload = [
            'act' => 'refund',
            'pid' => $this->requireConfigValue('upstream_pid', '上游商户ID'),
            'key' => $this->requireConfigValue('upstream_key', '上游 MD5 密钥'),
            'money' => $this->amountToMoney((int) ($order['refund_amount'] ?? 0)),
        ];

        $tradeNo = trim((string) ($order['chan_order_no'] ?? $order['chan_trade_no'] ?? ''));
        if ($tradeNo !== '') {
            $payload['trade_no'] = $tradeNo;
        } else {
            $payload['out_trade_no'] = $this->resolveOrderNo($order);
        }

        $response = $this->isMockEnabled()
            ? $this->buildMockRefundResponse($order)
            : $this->requestFormJson('POST', $this->resolveGatewayUrl('api_path', '/api.php'), $payload);
        if ((int) ($response['code'] ?? 0) !== 1) {
            return [
                'success' => false,
                'msg' => (string) ($response['msg'] ?? '上游 V1 退款失败'),
                'raw_data' => $response,
            ];
        }

        return [
            'success' => true,
            'msg' => (string) ($response['msg'] ?? 'success'),
            'chan_refund_no' => trim((string) ($response['refund_no'] ?? $response['trade_no'] ?? '')),
            'raw_data' => $response,
        ];
    }

    public function notify(Request $request): array
    {
        $payload = $this->resolveNotifyPayload($request);
        $this->verifyPayloadSignature(
            $payload,
            AuthConstant::API_SIGN_NAME_MD5,
            $this->requireConfigValue('upstream_key', '上游 MD5 密钥'),
            '上游 V1 回调验签失败'
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
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function requestQueryJson(string $url, array $query): array
    {
        $response = $this->request('GET', $url, [
            'query' => $query,
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
        $channelOrderNo = 'V1ORD' . strtoupper(substr(md5($seed), 0, 16));
        $channelTradeNo = 'V1TRD' . strtoupper(substr(sha1($seed), 0, 16));

        return [
            'code' => 1,
            'msg' => 'success',
            'trade_no' => $channelOrderNo,
            'api_trade_no' => $channelTradeNo,
            'payurl' => $this->resolveMockJumpUrl($channelTradeNo),
        ];
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
            $channelOrderNo = 'V1ORD' . strtoupper(substr(md5($seed), 0, 16));
            $channelTradeNo = 'V1TRD' . strtoupper(substr(sha1($seed), 0, 16));
        } elseif ($channelOrderNo === '') {
            $channelOrderNo = $channelTradeNo;
        } elseif ($channelTradeNo === '') {
            $channelTradeNo = $channelOrderNo;
        }

        return [
            'code' => 1,
            'msg' => '查询订单成功',
            'trade_no' => $channelOrderNo,
            'api_trade_no' => $channelTradeNo,
            'out_trade_no' => $this->resolveOrderNo($order),
            'status' => 1,
            'buyer' => 'MOCK_V1_BUYER',
            'param' => $this->resolveParamValue($order),
            'endtime' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function buildMockRefundResponse(array $order): array
    {
        $seed = strtolower(trim((string) ($order['refund_no'] ?? $this->resolveOrderNo($order))));

        return [
            'code' => 1,
            'msg' => '退款成功',
            'refund_no' => 'V1REF' . strtoupper(substr(md5($seed), 0, 16)),
        ];
    }

    /**
     * 构建 mock 跳转地址。
     */
    private function resolveMockJumpUrl(string $channelTradeNo): string
    {
        $baseUrl = trim((string) $this->getConfig('mock_jump_base_url', 'https://mock.epay.test/pay/v1'));
        if ($baseUrl === '') {
            $baseUrl = 'https://mock.epay.test/pay/v1';
        }

        return rtrim($baseUrl, '/') . '?trade_no=' . rawurlencode($channelTradeNo);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function normalizePayResponse(array $response): array
    {
        $payUrl = trim((string) ($response['payurl'] ?? ''));
        if ($payUrl !== '') {
            return [
                'type' => 'jump',
                'payurl' => $payUrl,
                'redirect_url' => $payUrl,
            ];
        }

        $qrcode = trim((string) ($response['qrcode'] ?? ''));
        if ($qrcode !== '') {
            return [
                'type' => 'qrcode',
                'qrcode' => $qrcode,
                'qrcode_text' => $qrcode,
            ];
        }

        $urlscheme = trim((string) ($response['urlscheme'] ?? ''));
        if ($urlscheme !== '') {
            return [
                'type' => 'urlscheme',
                'urlscheme' => $urlscheme,
                'redirect_url' => $urlscheme,
            ];
        }

        throw new PaymentException('上游 V1 未返回有效支付内容', 40200, [
            'response' => $response,
        ]);
    }
}
