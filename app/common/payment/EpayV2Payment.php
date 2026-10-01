<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\AuthConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\TransferConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\interface\RefundQueryInterface;
use app\common\interface\TransferPluginInterface;
use app\common\util\FormatHelper;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\service\payment\epay\EpaySignerManager;
use support\Request;
use support\Response;

/**
 * ePay V2 网关插件。
 *
 * 对接 docs/api/epay/epay_v2.md 中的支付、退款、查单、回调和转账协议。
 * V2 使用 RSA 签名；成功响应和携带签名的失败响应必须验签后才能交给业务服务层，
 * 未携带签名的失败响应只用于返回明确错误，不作为成功业务结果。
 */
class EpayV2Payment extends BasePayment implements PaymentInterface, PayPluginInterface, TransferPluginInterface, RefundQueryInterface
{
    /**
     * ePay 协议签名管理器。
     *
     * 通过容器懒加载，避免插件元信息读取阶段提前初始化签名服务。
     */
    private ?EpaySignerManager $epaySignerManager = null;

    /**
     * 插件元信息和后台配置表单。
     *
     * V2 需要商户私钥和平台公钥。`support_api` 只控制 API 创建订单能力；
     * 关闭后仍可使用页面跳转支付，查单、关单、退款和转账接口保持协议能力不变。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'epay_v2',
        'name' => '彩虹易支付V2',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'qqpay'],
        'transfer_types' => ['alipay', 'wxpay', 'qqpay', 'bank'],
        'config_schema' => [
            [
                'type' => 'input',
                'field' => 'api_url',
                'title' => '接口地址',
                'value' => '',
                'props' => [
                    'placeholder' => '例如：https://pay.example.com/',
                ],
                'validate' => [
                    ['required' => true, 'message' => '接口地址不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'pid',
                'title' => '商户ID',
                'value' => '',
                'props' => [
                    'placeholder' => '商户pid',
                ],
                'validate' => [
                    ['required' => true, 'message' => '商户ID不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'merchant_private_key',
                'title' => '商户私钥',
                'value' => '',
                'props' => [
                    'placeholder' => 'RSA 商户私钥',
                    'rows' => 6,
                ],
                'validate' => [
                    ['required' => true, 'message' => '商户私钥不能为空'],
                ],
            ],
            [
                'type' => 'textarea',
                'field' => 'platform_public_key',
                'title' => '平台公钥',
                'value' => '',
                'props' => [
                    'placeholder' => 'RSA 平台公钥',
                    'rows' => 6,
                ],
                'validate' => [
                    ['required' => true, 'message' => '平台公钥不能为空'],
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'support_api',
                'title' => '是否支持API创建订单',
                'value' => true,
                'props' => [
                    'checkedText' => '支持',
                    'uncheckedText' => '不支持',
                ],
            ],
        ],
    ];

    /**
     * 发起 V2 支付。
     *
     * 根据订单里的 `_submit_type` 决定走页面跳转还是 API 创建订单。
     * API 模式会按支付方式配置补充 method、授权码等产品参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    public function pay(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'type' => (string) $order['pay_type_code'],
            'out_trade_no' => (string) $order['pay_no'],
            'notify_url' => (string) $order['callback_url'],
            'name' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
            'money' => FormatHelper::amount((int) $order['amount']),
            'timestamp' => (string) time(),
        ];

        $returnUrl = (string) ($order['return_url'] ?? '');
        if ($returnUrl !== '') {
            $payload['return_url'] = $returnUrl;
        }

        $param = (string) ($order['extra']['merchant']['param'] ?? '');
        if ($param !== '') {
            $payload['param'] = $param;
        }

        if ((string) $order['extra']['_submit_type'] === EpayProtocolConstant::SUBMIT_TYPE_PAGE) {
            return $this->pendingPaymentResult($order, $this->submitPay($payload));
        }

        try {
            return $this->pendingPaymentResult($order, $this->apiPay($payload, $order));
        } catch (\Throwable $e) {
            // 网关 API 调用产品使用标准 method；保留本地请求事实，不证明上游已建单或猜其内部产品。
            $context = ['pay_type' => (string) $order['pay_type_code'],
                'pay_product' => (string) $order['extra']['payment']['method']];
            if ($e instanceof PaymentException) {
                $e->data(array_replace($e->getData(), $context));
                throw $e;
            }
            throw new PaymentUncertainException('上游 V2 创建响应无法确认', 40200,
                $context + ['exception_class' => $e::class]);
        }
    }

    /**
     * 使用 V2 页面跳转支付。
     *
     * 页面跳转阶段无法拿到上游平台单号，因此先使用本地支付单号占位。
     *
     * @param array<string, mixed> $payload V2 支付参数
     * @return array<string, mixed>
     */
    private function submitPay(array $payload): array
    {
        $payload = $this->signPayload($payload);
        $action = $this->gatewayUrl('/api/pay/submit');

        return [
            'pay_page' => 'jump',
            'pay_type' => (string) $payload['type'],
            'pay_product' => 'submit',
            'pay_action' => 'submitPay',
            'pay_params' => [
                'action' => $action,
                'method' => 'post',
                'payload' => $payload,
            ],
            'chan_order_no' => (string) $payload['out_trade_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 使用 V2 API 创建订单。
     *
     * API 创建会校验上游签名，并把上游返回的 pay_type 转换成收银台可识别的承接类型。
     *
     * @param array<string, mixed> $payload V2 支付参数
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function apiPay(array $payload, array $order): array
    {
        if (in_array($this->getConfig('support_api', true), [false, 0, '0'], true)) {
            throw new PaymentException('当前通道未开启 V2 API 创建订单', 40200);
        }

        $payload['method'] = (string) $order['extra']['payment']['method'];
        $payload['clientip'] = (string) $order['client_ip'];
        $payload['device'] = (string) $order['_env'];

        foreach (['auth_code', 'sub_openid', 'sub_appid'] as $key) {
            $value = (string) ($order['extra']['payment'][$key] ?? '');
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $response = $this->requestSignedJson('/api/pay/create', $payload, '上游 V2 下单响应验签失败');

        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new PaymentDefinitiveException((string) ($response['msg'] ?? '上游 V2 下单失败'), 40200, [
                'response' => $response,
            ]);
        }

        $payType = (string) ($response['pay_type'] ?? '');
        $payParams = $this->payParams($payType, $response);
        if ($payType === 'scan' && $response['pay_info']['status'] === PaymentPluginStatusConstant::SUCCESS
            && (!is_int($response['pay_info']['paid_amount'] ?? null)
                || $response['pay_info']['paid_amount'] !== (int) $order['amount'])) {
            throw new PaymentUncertainException('上游 V2 付款码成功摘要的金额不匹配', 40200);
        }
        $channelOrderNo = (string) ($response['trade_no'] ?? '');
        // 身份授权发生在上游建单之前，不能写本地单号占位而阻止后续回调补入真实上游号。
        if ($channelOrderNo === '' && $payType !== 'identity') {
            throw new PaymentUncertainException('上游 V2 未返回平台订单号', 40200);
        }

        return [
            'pay_page' => match ($payType) {
                'jump', 'identity' => 'jump',
                'html', 'qrcode', 'urlscheme', 'jsapi' => $payType,
                'app', 'scan' => 'page',
            },
            'pay_type' => (string) $payload['type'],
            'pay_product' => (string) $payload['method'],
            'pay_action' => $payType,
            'pay_params' => $payParams,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => (string) ($response['api_trade_no'] ?? ''),
        ];
    }

    /**
     * 查询 V2 支付订单。
     *
     * 优先使用上游 trade_no 查询；页面跳转阶段没有 trade_no 时，退回使用本地 out_trade_no。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'timestamp' => (string) time(),
        ];

        $payNo = (string) ($order['pay_no'] ?? '');
        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ($channelOrderNo !== '' && $channelOrderNo !== $payNo) {
            $payload['trade_no'] = $channelOrderNo;
        } else {
            $payload['out_trade_no'] = $payNo;
        }

        $response = $this->requestSignedJson('/api/pay/query', $payload, '上游 V2 查单响应验签失败');

        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new PaymentException((string) ($response['msg'] ?? '上游 V2 查单失败'), 40200);
        }

        $statusCode = (int) ($response['status'] ?? 0);
        $status = in_array($statusCode, [1, 2, 3], true)
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;
        $responsePayNo = trim((string) ($response['out_trade_no'] ?? ''));
        if ($responsePayNo === '' || !hash_equals($payNo, $responsePayNo)) {
            throw new PaymentException('上游 V2 查单返回支付单号不匹配', 40200, ['pay_no' => $payNo]);
        }
        $paidAmount = null;
        if ($status === PaymentPluginStatusConstant::SUCCESS) {
            $paidAmount = $this->yuanToCents($response['money'] ?? null, '上游 V2 查单金额');
            if ($paidAmount !== (int) ($order['amount'] ?? -1)) {
                throw new PaymentException('上游 V2 查单返回金额不匹配', 40200, ['pay_no' => $payNo]);
            }
        }

        return [
            'status' => $status,
            'pay_no' => $payNo,
            'paid_amount' => $paidAmount,
            'chan_order_no' => (string) ($response['trade_no'] ?? $channelOrderNo),
            'chan_trade_no' => (string) ($response['api_trade_no'] ?? ''),
            'channel_status' => (string) $statusCode,
            'message' => (string) ($response['msg'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($response['endtime'] ?? null) : null,
            'raw_data' => $response,
        ];
    }

    /**
     * 关闭 V2 支付订单。
     *
     * 关单同样按 trade_no 优先、out_trade_no 兜底，保持和查单、退款的订单标识选择一致。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'timestamp' => (string) time(),
        ];

        $payNo = (string) ($order['pay_no'] ?? '');
        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ($channelOrderNo !== '' && $channelOrderNo !== $payNo) {
            $payload['trade_no'] = $channelOrderNo;
        } else {
            $payload['out_trade_no'] = $payNo;
        }

        $response = $this->requestSignedJson('/api/pay/close', $payload, '上游 V2 关单响应验签失败');
        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new PaymentDefinitiveException((string) ($response['msg'] ?? '上游 V2 关单失败'), 40200);
        }

        return [
            'status' => PaymentPluginStatusConstant::CLOSED,
            'pay_no' => $payNo,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => (string) ($response['msg'] ?? 'success'),
        ];
    }

    /**
     * 提交 V2 订单退款。
     *
     * 退款请求需要携带系统退款单号作为 out_refund_no，方便后续对账和幂等追踪。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'money' => FormatHelper::amount((int) $order['refund_amount']),
            'timestamp' => (string) time(),
        ];

        $payNo = (string) ($order['pay_no'] ?? '');
        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ($channelOrderNo !== '' && $channelOrderNo !== $payNo) {
            $payload['trade_no'] = $channelOrderNo;
        } else {
            $payload['out_trade_no'] = $payNo;
        }

        $refundNo = (string) ($order['refund_no'] ?? '');
        if ($refundNo === '') {
            throw new PaymentDefinitiveException('上游 V2 退款缺少退款单号', 40200);
        }
        $payload['out_refund_no'] = $refundNo;

        $response = $this->requestSignedJson('/api/pay/refund', $payload, '上游 V2 退款响应验签失败');
        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new PaymentDefinitiveException((string) ($response['msg'] ?? '上游 V2 退款失败'), 40200);
        }

        $result = $this->refundResult($response, $order);
        if ($result['status'] === PaymentPluginStatusConstant::FAILED) {
            throw new PaymentDefinitiveException('上游 V2 退款失败', 40200, ['refund_no' => $refundNo]);
        }

        return $result;
    }

    /**
     * 查询原退款单，不重新派发退款。
     *
     * @param array<string, mixed> $order 标准退款查询参数
     * @return array<string, mixed> 已核对身份、金额和状态的退款事实
     */
    public function queryRefund(array $order): array
    {
        $response = $this->requestSignedJson('/api/pay/refundquery', [
            'pid' => (string) $this->getConfig('pid'),
            'out_refund_no' => (string) $order['refund_no'],
            'timestamp' => (string) time(),
        ], '上游 V2 退款查询响应验签失败');
        if ((int) ($response['code'] ?? -1) !== 0) {
            throw new PaymentUncertainException((string) ($response['msg'] ?? '上游 V2 退款查询失败'), 40200);
        }

        return $this->refundResult($response, $order);
    }

    /**
     * 创建和查询共用同一响应契约；通信成功不能代替资金退款成功。
     *
     * @param array<string, mixed> $response 已验签的上游响应
     * @param array<string, mixed> $order 本地标准退款参数
     * @return array<string, mixed> 标准退款结果
     */
    private function refundResult(array $response, array $order): array
    {
        foreach (['out_refund_no' => 'refund_no', 'out_trade_no' => 'pay_no'] as $remote => $local) {
            if (!hash_equals((string) $order[$local], (string) ($response[$remote] ?? ''))) {
                throw new PaymentUncertainException('上游 V2 退款返回订单标识不匹配', 40200, ['field' => $remote]);
            }
        }
        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ((string) ($response['trade_no'] ?? '') === ''
            || ($channelOrderNo !== '' && $channelOrderNo !== (string) $order['pay_no']
                && !hash_equals($channelOrderNo, (string) $response['trade_no']))) {
            throw new PaymentUncertainException('上游 V2 退款返回平台订单号不匹配', 40200);
        }
        try {
            $refundAmount = $this->yuanToCents($response['money'] ?? null, '上游 V2 退款金额');
        } catch (PaymentException $e) {
            throw new PaymentUncertainException($e->getMessage(), 40200);
        }
        if ($refundAmount !== (int) $order['refund_amount']) {
            throw new PaymentUncertainException('上游 V2 退款返回金额不匹配', 40200);
        }
        $channelRefundNo = (string) ($response['refund_no'] ?? '');
        $storedRefundNo = (string) ($order['chan_refund_no'] ?? '');
        if ($channelRefundNo === '' || ($storedRefundNo !== '' && !hash_equals($storedRefundNo, $channelRefundNo))) {
            throw new PaymentUncertainException('上游 V2 退款返回平台退款单号不匹配', 40200);
        }
        $status = match ($response['status'] ?? null) {
            EpayProtocolConstant::V2_REFUND_STATUS_SUCCESS => PaymentPluginStatusConstant::SUCCESS,
            EpayProtocolConstant::V2_REFUND_STATUS_PENDING => PaymentPluginStatusConstant::PENDING,
            EpayProtocolConstant::V2_REFUND_STATUS_FAILED => PaymentPluginStatusConstant::FAILED,
            default => throw new PaymentUncertainException('上游 V2 未返回可确认的退款状态', 40200),
        };

        return [
            'status' => $status,
            'refund_no' => (string) $order['refund_no'],
            'pay_no' => (string) $order['pay_no'],
            'refund_amount' => $refundAmount,
            'chan_refund_no' => $channelRefundNo,
            'message' => match ($status) {
                PaymentPluginStatusConstant::SUCCESS => '上游 V2 退款成功',
                PaymentPluginStatusConstant::PENDING => '上游 V2 退款尚未完成',
                default => '上游 V2 退款失败或关闭',
            },
        ];
    }

    /**
     * 解析并验签 V2 支付回调。
     *
     * 回调先校验时间戳和 RSA 签名，再把 trade_status 映射为平台内部支付状态。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = (array) $request->all();
        $this->verifyPayload($payload, '上游 V2 回调验签失败');

        $channelOrderNo = (string) ($payload['trade_no'] ?? '');
        if ($channelOrderNo === '') {
            throw new PaymentException('上游 V2 回调缺少平台订单号', 40200);
        }

        $tradeStatus = strtoupper((string) ($payload['trade_status'] ?? ''));
        $status = $tradeStatus === NotifyConstant::EPAY_TRADE_STATUS_SUCCESS
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;

        return [
            'status' => $status,
            'pay_no' => trim((string) ($payload['out_trade_no'] ?? '')),
            'paid_amount' => $status === PaymentPluginStatusConstant::SUCCESS
                ? $this->yuanToCents($payload['money'] ?? null, '上游 V2 回调金额')
                : null,
            'message' => $tradeStatus,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelOrderNo,
            'channel_status' => $tradeStatus,
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($payload['endtime'] ?? null) : null,
        ];
    }

    /**
     * 返回上游要求的成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    /**
     * 返回上游要求的失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'fail';
    }

    /**
     * 发起 V2 转账。
     *
     * 转账属于独立插件能力，由 TransferPluginInterface 调用，不参与支付订单状态流转。
     *
     * @param array<string, mixed> $order 标准插件转账参数
     * @return array<string, mixed>
     */
    public function transfer(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'type' => (string) $order['type'],
            'account' => (string) $order['account'],
            'name' => (string) $order['name'],
            'money' => FormatHelper::amount((int) $order['amount']),
            'out_biz_no' => (string) $order['biz_no'],
            'timestamp' => (string) time(),
        ];

        foreach (['remark', 'bookid'] as $key) {
            $value = (string) ($order[$key] ?? '');
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $response = $this->requestSignedJson('/api/transfer/submit', $payload, '上游 V2 转账响应验签失败');

        return $this->transferResult($response);
    }

    /**
     * 查询 V2 转账状态。
     *
     * 优先使用上游 biz_no 查询；没有上游单号时使用本地 out_biz_no。
     *
     * @param array<string, mixed> $order 标准插件转账查询参数
     * @return array<string, mixed>
     */
    public function transferQuery(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'timestamp' => (string) time(),
        ];

        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ($channelOrderNo !== '') {
            $payload['biz_no'] = $channelOrderNo;
        } else {
            $payload['out_biz_no'] = (string) $order['biz_no'];
        }

        $response = $this->requestSignedJson('/api/transfer/query', $payload, '上游 V2 转账查询响应验签失败');

        return $this->transferResult($response);
    }

    /**
     * 查询 V2 转账余额。
     *
     * 余额查询只返回上游可用余额与费率信息，不产生本地业务状态变更。
     *
     * @param array<string, mixed> $order 查询参数
     * @return array<string, mixed>
     */
    public function transferBalance(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'timestamp' => (string) time(),
        ];

        $response = $this->requestSignedJson('/api/transfer/balance', $payload, '上游 V2 转账余额响应验签失败');

        return [
            'success' => (int) ($response['code'] ?? -1) === 0,
            'available_money' => (string) ($response['available_money'] ?? ''),
            'transfer_rate' => (string) ($response['transfer_rate'] ?? ''),
            'message' => (string) ($response['msg'] ?? ''),
            'raw_data' => $response,
        ];
    }

    /**
     * 发送签名表单并解码 JSON 响应。
     *
     * V2 接口统一走 POST 表单：请求先用商户私钥签名；成功响应及任何携带签名的
     * 失败响应再使用平台公钥验签。无签名失败响应不会被当作成功结果使用。
     *
     * @param string $path 接口路径
     * @param array<string, mixed> $payload 请求参数
     * @param string $verifyMessage 验签失败消息
     * @return array<string, mixed>
     */
    private function requestSignedJson(string $path, array $payload, string $verifyMessage): array
    {
        $response = $this->request('POST', $this->gatewayUrl($path), [
            'form_params' => $this->signPayload($payload),
            'headers' => ['Accept' => 'application/json'],
        ]);

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PaymentException('上游网关响应不是合法 JSON', 40200, [
                'body_excerpt' => mb_strcut(preg_replace('/\s+/', ' ', $body) ?? $body, 0, 240, 'UTF-8'),
            ]);
        }

        $hasSignature = trim((string) ($decoded['sign'] ?? '')) !== '';
        $isSuccessResponse = !array_key_exists('code', $decoded) || (int) $decoded['code'] === 0;
        if ($isSuccessResponse || $hasSignature) {
            $this->verifyPayload($decoded, $verifyMessage);
        }

        return $decoded;
    }

    /**
     * 使用商户私钥给请求参数签名。
     *
     * @param array<string, mixed> $payload 待签名参数
     * @return array<string, mixed>
     */
    private function signPayload(array $payload): array
    {
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_RSA;
        $payload['sign'] = $this->signerManager()->sign(
            $payload,
            AuthConstant::API_SIGN_NAME_RSA,
            (string) $this->getConfig('merchant_private_key')
        );

        return $payload;
    }

    /**
     * 校验上游响应或回调签名。
     *
     * 同时限制时间戳偏差，缩小旧响应或旧通知的重放窗口；业务幂等仍由订单服务负责。
     *
     * @param array<string, mixed> $payload 待验签参数
     * @param string $message 验签失败提示
     * @return void
     */
    private function verifyPayload(array $payload, string $message): void
    {
        $timestamp = (int) ($payload['timestamp'] ?? 0);
        if ($timestamp <= 0 || abs(time() - $timestamp) > 300) {
            throw new PaymentException($message, 40200);
        }

        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '' || !$this->signerManager()->verify(
            $payload,
            (string) ($payload['sign_type'] ?? AuthConstant::API_SIGN_NAME_RSA),
            $sign,
            (string) $this->getConfig('platform_public_key')
        )) {
            throw new PaymentException($message, 40200);
        }
    }

    /**
     * 按承接页组件固定字段包装 V2 原始支付参数。
     *
     * 只消费文档公开类型，未知动作名不能当作组件名。JSAPI 允许对象或 JSON 对象字符串；
     * scan 摘要不推进本地资金状态，仍由原查单/回调链路确认。响应结构不完整按不确定处理。
     *
     * @param string $payType 上游返回的支付内容类型
     * @param array<string, mixed> $response 上游原始响应
     * @return array<string, mixed>
     */
    private function payParams(string $payType, array $response): array
    {
        $payInfo = $response['pay_info'] ?? null;
        if (in_array($payType, ['qrcode', 'html', 'jump', 'identity', 'urlscheme'], true)) {
            if (!is_string($payInfo) || $payInfo === '') {
                throw new PaymentUncertainException('上游 V2 支付内容缺少有效文本', 40200);
            }
            $field = match ($payType) {
                'qrcode' => 'qrcode', 'html' => 'html', 'jump', 'identity' => 'url', 'urlscheme' => 'urlscheme',
            };
            return [$field => $payInfo];
        }

        if ($payType === 'jsapi') {
            $params = is_string($payInfo) ? json_decode($payInfo, true) : $payInfo;
            if (!is_array($params) || $params === [] || array_is_list($params)) {
                throw new PaymentUncertainException('上游 V2 JSAPI 内容不是有效参数对象', 40200);
            }
            return $params;
        }
        if ($payType === 'app') {
            if ((!is_string($payInfo) && !is_array($payInfo)) || $payInfo === '' || $payInfo === []
                || (is_array($payInfo) && array_is_list($payInfo))) {
                throw new PaymentUncertainException('上游 V2 APP 内容缺少调起参数', 40200);
            }
            return ['_page' => 'page', 'params' => $payInfo, 'description' => '请在商户 App 内调用支付 SDK 继续付款。'];
        }
        if ($payType === 'scan') {
            if (!is_array($payInfo) || !in_array($payInfo['status'] ?? null, ['pending', 'success'], true)) {
                throw new PaymentUncertainException('上游 V2 付款码结果缺少明确状态', 40200);
            }
            return ['_page' => 'paymentPending', 'description' => '付款码请求已受理，等待平台回调或查单确认支付结果。'];
        }

        throw new PaymentUncertainException('上游 V2 返回了未约定的支付内容类型', 40200, ['pay_type' => $payType]);
    }

    /**
     * 归一化 V2 转账响应。
     *
     * @param array<string, mixed> $response 上游原始响应
     * @return array<string, mixed>
     */
    private function transferResult(array $response): array
    {
        $statusCode = (int) ($response['status'] ?? TransferConstant::TRANSFER_STATUS_PENDING);
        $status = match ($statusCode) {
            TransferConstant::TRANSFER_STATUS_SUCCESS => PaymentPluginStatusConstant::SUCCESS,
            TransferConstant::TRANSFER_STATUS_FAILED,
            TransferConstant::TRANSFER_STATUS_CLOSED => PaymentPluginStatusConstant::FAILED,
            default => PaymentPluginStatusConstant::PENDING,
        };

        return [
            'success' => (int) ($response['code'] ?? -1) === 0,
            'status' => $status,
            'status_code' => $statusCode,
            'msg' => (string) ($response['errmsg'] ?? $response['msg'] ?? ''),
            'chan_order_no' => (string) ($response['biz_no'] ?? $response['orderid'] ?? ''),
            'chan_trade_no' => (string) ($response['orderid'] ?? $response['biz_no'] ?? ''),
            'orderid' => (string) ($response['orderid'] ?? ''),
            'succeeded_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($response['paydate'] ?? null) : null,
            'raw_data' => $response,
        ];
    }

    /**
     * 获取签名管理器。
     *
     * @return EpaySignerManager
     */
    private function signerManager(): EpaySignerManager
    {
        if ($this->epaySignerManager === null) {
            /** @var EpaySignerManager $manager */
            $manager = container_get(EpaySignerManager::class);
            $this->epaySignerManager = $manager;
        }

        return $this->epaySignerManager;
    }

    /**
     * 拼接上游网关地址。
     *
     * 后台配置只保存根地址，具体协议路径由插件内部统一补齐。
     *
     * @param string $path 协议路径
     * @return string 完整网关地址
     */
    private function gatewayUrl(string $path): string
    {
        return rtrim((string) $this->getConfig('api_url'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * 将两位小数元金额转换为整数分。
     *
     * @param mixed $value 渠道金额
     * @param string $field 金额字段说明
     * @return int 金额，单位分
     */
    private function yuanToCents(mixed $value, string $field): int
    {
        $text = trim((string) $value);
        if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $text, $matches) !== 1) {
            throw new PaymentException($field . '格式无效', 40200);
        }

        return ((int) $matches[1] * 100) + (int) str_pad((string) ($matches[2] ?? ''), 2, '0');
    }
}
