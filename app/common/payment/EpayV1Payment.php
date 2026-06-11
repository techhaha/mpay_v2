<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\constant\AuthConstant;
use app\common\constant\EpayProtocolConstant;
use app\common\constant\NotifyConstant;
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
 * 对接 docs/api/epay/epay_v1.md 中的 submit.php、mapi.php、api.php 和 notify_url 协议。
 * V1 使用 MD5 签名，页面跳转支付与 mapi 接口支付共用同一套基础参数。
 */
class EpayV1Payment extends BasePayment implements PaymentInterface, PayPluginInterface
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
     * V1 只需要网关地址、商户 pid、MD5 密钥和 mapi 开关。页面跳转支付不依赖 mapi；
     * 当通道关闭 mapi 时，收银台仍可以通过 submit.php 跳转到上游页面。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'epay_v1',
        'name' => '彩虹易支付V1',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_DIRECT,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
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
                'type' => 'password',
                'field' => 'api_key',
                'title' => '商户密钥',
                'value' => '',
                'props' => [
                    'placeholder' => 'MD5格式密钥',
                ],
                'validate' => [
                    ['required' => true, 'message' => 'MD5密钥不能为空'],
                ],
            ],
            [
                'type' => 'switch',
                'field' => 'support_mapi',
                'title' => '是否支持mapi接口',
                'value' => true,
                'props' => [
                    'checkedText' => '支持',
                    'uncheckedText' => '不支持',
                ],
            ],
        ],
    ];

    /**
     * 发起 V1 支付。
     *
     * 根据订单里的 `_submit_type` 决定走 submit.php 页面跳转还是 mapi.php 接口创建。
     * 两种方式都会返回统一的插件下单结果，方便支付服务层继续处理收银台承接。
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
            return $this->submitPay($payload);
        }

        return $this->mapiPay($payload, $order);
    }

    /**
     * 使用 V1 submit.php 页面跳转支付。
     *
     * submit.php 是浏览器跳转协议，发起时拿不到上游平台单号，因此先使用本地支付单号占位。
     * 真正的上游 trade_no 会在异步回调或后续查单时补齐。
     *
     * @param array<string, mixed> $payload V1 支付参数
     * @return array<string, mixed>
     */
    private function submitPay(array $payload): array
    {
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_MD5;
        $payload['sign'] = $this->signerManager()->sign(
            $payload,
            AuthConstant::API_SIGN_NAME_MD5,
            (string) $this->getConfig('api_key')
        );

        $action = $this->gatewayUrl('/submit.php');
        $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $url = $action . (str_contains($action, '?') ? '&' : '?') . $query;

        return [
            'pay_page' => 'jump',
            'pay_type' => (string) $payload['type'],
            'pay_product' => (string) $payload['type'],
            'pay_action' => 'submitPay',
            'pay_params' => [
                'url' => $url,
                'action' => $action,
                'method' => 'get',
                'payload' => $payload,
            ],
            // 页面跳转阶段还拿不到上游真实单号，先用本地 out_trade_no/pay_no 占位。
            'chan_order_no' => (string) $payload['out_trade_no'],
            'chan_trade_no' => '',
        ];
    }

    /**
     * 使用 V1 mapi.php 接口支付。
     *
     * mapi.php 会直接返回二维码、跳转链接或 URL Scheme，并返回平台订单号。
     * 如果上游没有返回有效承接内容或 trade_no，按创建订单失败处理。
     *
     * @param array<string, mixed> $payload V1 支付参数
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function mapiPay(array $payload, array $order): array
    {
        if (in_array($this->getConfig('support_mapi', true), [false, 0, '0'], true)) {
            throw new PaymentException('当前通道未开启 mapi 接口', 40200);
        }

        $payload['clientip'] = (string) $order['client_ip'];
        $payload['device'] = (string) $order['_env'];
        $payload['sign_type'] = AuthConstant::API_SIGN_NAME_MD5;
        $payload['sign'] = $this->signerManager()->sign(
            $payload,
            AuthConstant::API_SIGN_NAME_MD5,
            (string) $this->getConfig('api_key')
        );

        $response = $this->requestJson('POST', $this->gatewayUrl('/mapi.php'), [
            'form_params' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if ((int) ($response['code'] ?? 0) !== 1) {
            throw new PaymentException((string) ($response['msg'] ?? '上游 V1 下单失败'), 40200, [
                'response' => $response,
            ]);
        }

        $payPage = '';
        if ((string) ($response['payurl'] ?? '') !== '') {
            $payPage = 'jump';
        } elseif ((string) ($response['qrcode'] ?? '') !== '') {
            $payPage = 'qrcode';
        } elseif ((string) ($response['urlscheme'] ?? '') !== '') {
            $payPage = 'urlscheme';
        }

        if ($payPage === '') {
            throw new PaymentException('上游 V1 未返回有效支付内容', 40200, [
                'response' => $response,
            ]);
        }

        $channelOrderNo = (string) ($response['trade_no'] ?? '');
        if ($channelOrderNo === '') {
            throw new PaymentException('上游 V1 未返回平台订单号', 40200, [
                'response' => $response,
            ]);
        }

        return [
            'pay_page' => $payPage,
            'pay_type' => $payload['type'],
            'pay_product' => $payload['type'],
            'pay_action' => 'mapiPay',
            'pay_params' => $this->mapiPayParams($payPage, $response),
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => '',
        ];
    }

    /**
     * 按承接页组件固定字段包装 V1 mapi 原始支付参数。
     *
     * @param string $payPage 承接页类型
     * @param array<string, mixed> $response V1 mapi 原始响应
     * @return array<string, mixed>
     */
    private function mapiPayParams(string $payPage, array $response): array
    {
        return match ($payPage) {
            'qrcode' => [
                'qrcode' => (string) $response['qrcode'],
                'raw' => $response,
            ],
            'jump' => [
                'url' => (string) $response['payurl'],
                'raw' => $response,
            ],
            'urlscheme' => [
                'urlscheme' => (string) $response['urlscheme'],
                'raw' => $response,
            ],
            default => [
                'raw' => $response,
            ],
        };
    }

    /**
     * 查询 V1 支付订单。
     *
     * 优先使用上游 trade_no 查询；页面跳转阶段没有 trade_no 时，退回使用本地 out_trade_no。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        $payload = [
            'act' => 'order',
            'pid' => (string) $this->getConfig('pid'),
            'key' => (string) $this->getConfig('api_key'),
        ];

        $payNo = (string) ($order['pay_no'] ?? '');
        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ($channelOrderNo !== '' && $channelOrderNo !== $payNo) {
            $payload['trade_no'] = $channelOrderNo;
        } else {
            $payload['out_trade_no'] = $payNo;
        }

        $response = $this->requestJson('GET', $this->gatewayUrl('/api.php'), [
            'query' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if ((int) ($response['code'] ?? 0) !== 1) {
            return [
                'success' => false,
                'msg' => (string) ($response['msg'] ?? '上游 V1 查单失败'),
                'raw_data' => $response,
            ];
        }

        $status = (int) ($response['status'] ?? 0) === 1
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;

        return [
            'success' => true,
            'status' => $status,
            'channel_order_no' => (string) ($response['trade_no'] ?? $channelOrderNo),
            'channel_trade_no' => (string) ($response['api_trade_no'] ?? ''),
            'channel_status' => (string) ($response['status'] ?? ''),
            'message' => (string) ($response['msg'] ?? ''),
            'paid_at' => $status === PaymentPluginStatusConstant::SUCCESS ? ($response['endtime'] ?? null) : null,
        ];
    }

    /**
     * V1 协议没有关单接口。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        throw new PaymentException('上游 ePay V1 协议不支持关单', 40200, [
            'plugin_code' => $this->getCode(),
            'pay_no' => (string) ($order['pay_no'] ?? ''),
        ]);
    }

    /**
     * 提交 V1 订单退款。
     *
     * 与查单一样，优先使用上游 trade_no；没有时使用 out_trade_no。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        $payload = [
            'pid' => (string) $this->getConfig('pid'),
            'key' => (string) $this->getConfig('api_key'),
            'money' => FormatHelper::amount((int) $order['refund_amount']),
        ];

        $payNo = (string) ($order['pay_no'] ?? '');
        $channelOrderNo = (string) ($order['chan_order_no'] ?? '');
        if ($channelOrderNo !== '' && $channelOrderNo !== $payNo) {
            $payload['trade_no'] = $channelOrderNo;
        } else {
            $payload['out_trade_no'] = $payNo;
        }

        $refundNo = (string) ($order['refund_no'] ?? '');
        if ($refundNo !== '') {
            $payload['refund_no'] = $refundNo;
        }

        $response = $this->requestJson('POST', $this->gatewayUrl('/api.php?act=refund'), [
            'form_params' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return [
            'success' => (int) ($response['code'] ?? 0) === 1,
            'msg' => (string) ($response['msg'] ?? ''),
            'chan_refund_no' => (string) ($response['refund_no'] ?? $response['trade_no'] ?? ''),
            'raw_data' => $response,
        ];
    }

    /**
     * 解析并验签 V1 支付回调。
     *
     * V1 回调参数通过 MD5 验签，验签通过后由 trade_status 映射为平台内部支付状态。
     *
     * @param Request $request 回调请求
     * @return array<string, mixed>
     */
    public function notify(Request $request): array
    {
        $payload = (array) $request->all();
        $sign = (string) ($payload['sign'] ?? '');
        if ($sign === '' || !$this->signerManager()->verify(
            $payload,
            AuthConstant::API_SIGN_NAME_MD5,
            $sign,
            (string) $this->getConfig('api_key')
        )) {
            throw new PaymentException('上游 V1 回调验签失败', 40200);
        }

        $channelOrderNo = (string) ($payload['trade_no'] ?? '');
        if ($channelOrderNo === '') {
            throw new PaymentException('上游 V1 回调缺少平台订单号', 40200);
        }

        $tradeStatus = strtoupper((string) ($payload['trade_status'] ?? ''));
        $status = $tradeStatus === NotifyConstant::EPAY_TRADE_STATUS_SUCCESS
            ? PaymentPluginStatusConstant::SUCCESS
            : PaymentPluginStatusConstant::PENDING;

        return [
            'status' => $status,
            'message' => $tradeStatus,
            'channel_order_no' => $channelOrderNo,
            'channel_trade_no' => $channelOrderNo,
            'channel_status' => $tradeStatus,
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
     * 获取签名管理器。
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
     */
    private function gatewayUrl(string $path): string
    {
        return rtrim((string) $this->getConfig('api_url'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * 发送上游 HTTP 请求并解析 JSON 响应。
     *
     * @param string $method 请求方法
     * @param string $url 请求地址
     * @param array<string, mixed> $options HTTP 请求选项
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $options): array
    {
        $response = $this->request($method, $url, $options);
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PaymentException('上游网关响应不是合法 JSON', 40200, [
                'url' => $url,
                'body_excerpt' => mb_strcut(preg_replace('/\s+/', ' ', $body) ?? $body, 0, 240, 'UTF-8'),
            ]);
        }

        return $decoded;
    }
}
