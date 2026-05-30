<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\sdk\zyu\ZyuClient;
use app\common\sdk\zyu\ZyuSdkException;
use app\common\util\FormatHelper;
use app\exception\PaymentException;
use support\Request;
use support\Response;

/**
 * 知宇支付插件。
 */
class ZyuApiPayment extends BasePayment implements PaymentInterface, PayPluginInterface
{
    private const MODE_FORM = 'form';
    private const MODE_JUMP = 'jump';
    private const MODE_QRCODE = 'qrcode';

    private ?ZyuClient $client = null;

    /**
     * 插件元信息。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'zyu_api',
        'name' => '知宇支付API',
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
                'field' => 'api_url',
                'title' => '支付网关地址',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '支付网关地址不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'merchant_id',
                'title' => '商户号',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '商户号不能为空'],
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
                'field' => 'bank_code',
                'title' => '通道编码',
                'value' => '',
                'validate' => [
                    ['required' => true, 'message' => '通道编码不能为空'],
                ],
            ],
            [
                'type' => 'radio',
                'field' => 'pay_mode',
                'title' => '支付跳转模式',
                'value' => self::MODE_FORM,
                'options' => [
                    ['label' => '表单跳转', 'value' => self::MODE_FORM],
                    ['label' => '请求后跳转', 'value' => self::MODE_JUMP],
                    ['label' => '请求后扫码', 'value' => self::MODE_QRCODE],
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
        $payload = $this->payPayload($order);
        $mode = $this->payMode();

        if ($mode === self::MODE_FORM) {
            return [
                'pay_page' => 'html',
                'pay_type' => (string) $order['pay_type_code'],
                'pay_product' => $this->configText('bank_code'),
                'pay_action' => 'form',
                'pay_params' => [
                    'html' => $this->formHtml($this->client()->payPayload($payload)),
                ],
                'chan_order_no' => (string) $order['pay_no'],
                'chan_trade_no' => '',
            ];
        }

        try {
            $data = $this->client()->pay($payload);
        } catch (ZyuSdkException $e) {
            throw new PaymentException('知宇支付下单失败：' . $e->getMessage(), 40200);
        }

        $url = $this->payUrl($data);
        $payPage = $mode === self::MODE_QRCODE ? 'qrcode' : 'jump';

        return [
            'pay_page' => $payPage,
            'pay_type' => (string) $order['pay_type_code'],
            'pay_product' => $this->configText('bank_code'),
            'pay_action' => 'apiPay',
            'pay_params' => $payPage === 'qrcode'
                ? ['qrcode' => $url, 'raw' => $data]
                : ['url' => $url, 'raw' => $data],
            'chan_order_no' => (string) $order['pay_no'],
            'chan_trade_no' => (string) ($data['transaction_id'] ?? ''),
        ];
    }

    /**
     * 知宇旧插件未提供主动查单。
     *
     * @param array<string, mixed> $order 标准插件查单参数
     * @return array<string, mixed>
     */
    public function query(array $order): array
    {
        return [
            'success' => false,
            'status' => PaymentPluginStatusConstant::PENDING,
            'msg' => '知宇支付插件暂不支持主动查单',
        ];
    }

    /**
     * 知宇旧插件未提供关单。
     *
     * @param array<string, mixed> $order 标准插件关单参数
     * @return array<string, mixed>
     */
    public function close(array $order): array
    {
        return [
            'success' => false,
            'msg' => '知宇支付插件暂不支持关单',
        ];
    }

    /**
     * 知宇旧插件未提供退款。
     *
     * @param array<string, mixed> $order 标准插件退款参数
     * @return array<string, mixed>
     */
    public function refund(array $order): array
    {
        return [
            'success' => false,
            'msg' => '知宇支付插件暂不支持退款',
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
            throw new PaymentException('知宇支付回调验签失败', 40200);
        }

        $success = (string) ($payload['returncode'] ?? '') === '00';

        return [
            'status' => $success ? PaymentPluginStatusConstant::SUCCESS : PaymentPluginStatusConstant::FAILED,
            'message' => (string) ($payload['returncode'] ?? ''),
            'channel_order_no' => (string) ($payload['orderid'] ?? ''),
            'channel_trade_no' => (string) ($payload['transaction_id'] ?? ''),
            'channel_status' => (string) ($payload['returncode'] ?? ''),
        ];
    }

    /**
     * 返回知宇支付成功应答。
     */
    public function notifySuccess(): string|Response
    {
        return 'OK';
    }

    /**
     * 返回知宇支付失败应答。
     */
    public function notifyFail(): string|Response
    {
        return 'FAIL';
    }

    /**
     * 构造下单参数。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function payPayload(array $order): array
    {
        return [
            'pay_memberid' => $this->configText('merchant_id'),
            'pay_orderid' => (string) $order['pay_no'],
            'pay_amount' => FormatHelper::amount((int) $order['amount']),
            'pay_applydate' => date('Y-m-d H:i:s'),
            'pay_bankcode' => $this->configText('bank_code'),
            'pay_notifyurl' => (string) $order['callback_url'],
            'pay_callbackurl' => (string) $order['return_url'],
            'pay_productname' => mb_strcut((string) $order['subject'], 0, 127, 'UTF-8'),
        ];
    }

    /**
     * 获取上游支付地址。
     *
     * @param array<string, mixed> $data 上游响应
     */
    private function payUrl(array $data): string
    {
        $value = $data['data'] ?? $data['payurl'] ?? $data['payUrl'] ?? $data['pay_url'] ?? '';
        if (is_array($value)) {
            $value = $value['payUrl'] ?? '';
        }
        $url = (string) $value;
        if ($url === '') {
            throw new PaymentException('知宇支付未返回支付地址', 40200, ['response' => $data]);
        }

        return $url;
    }

    /**
     * 生成自动提交表单。
     *
     * @param array<string, mixed> $payload 表单参数
     */
    private function formHtml(array $payload): string
    {
        $html = '<form action="' . htmlspecialchars($this->configText('api_url'), ENT_QUOTES, 'UTF-8') . '" method="post" id="dopay">';
        foreach ($payload as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '</form><script>document.getElementById("dopay").submit();</script>';

        return $html;
    }

    /**
     * 支付模式。
     */
    private function payMode(): string
    {
        $mode = $this->configText('pay_mode');
        return in_array($mode, [self::MODE_FORM, self::MODE_JUMP, self::MODE_QRCODE], true)
            ? $mode
            : self::MODE_FORM;
    }

    /**
     * 获取 SDK 客户端。
     */
    private function client(): ZyuClient
    {
        if ($this->client === null) {
            $this->client = new ZyuClient([
                'api_url' => $this->configText('api_url'),
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
