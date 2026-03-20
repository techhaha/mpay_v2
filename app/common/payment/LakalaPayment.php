<?php
declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\contracts\PaymentInterface;
use app\exceptions\PaymentException;
use support\Request;
use support\Response;

/**
 * 拉卡拉支付插件（最小可用示例）
 *
 * 目的：先把 API 下单链路跑通，让现有 DB 配置（ma_pay_plugin=lakala）可用。
 * 后续你可以把这里替换为真实拉卡拉对接逻辑（HTTP 下单、验签回调等）。
 */
class LakalaPayment extends BasePayment implements PaymentInterface
{
    protected array $paymentInfo = [
        'code'           => 'lakala',
        'name'           => '拉卡拉（示例）',
        'author'         => '',
        'link'           => '',
        'pay_types'      => ['alipay', 'wechat'],
        'transfer_types' => [],
        'config_schema'  => [
            'fields' => [
                ['field' => 'notify_url', 'label' => '异步通知地址', 'type' => 'text', 'required' => false],
            ],
        ],
    ];

    public function pay(array $order): array
    {
        $orderId = (string)($order['order_id'] ?? '');
        $amount  = (string)($order['amount'] ?? '0.00');
        $extra   = is_array($order['extra'] ?? null) ? $order['extra'] : [];

        if ($orderId === '') {
            throw new PaymentException('缺少订单号', 402);
        }

        // 这里先返回“可联调”的 pay_params：默认给一个 qrcode 字符串
        // 真实实现中应调用拉卡拉下单接口，返回二维码链接/支付链接/预支付信息等。
        $qrcode = $extra['mock_qrcode'] ?? ('LAKALA_MOCK_QRCODE:' . $orderId . ':' . $amount);

        return [
            'pay_params'    => [
                'type'       => 'qrcode',
                'qrcode_url' => $qrcode,
                'qrcode_data'=> $qrcode,
            ],
            'chan_order_no' => $orderId,
            'chan_trade_no' => '',
        ];
    }

    public function query(array $order): array
    {
        throw new PaymentException('LakalaPayment::query 暂未实现', 402);
    }

    public function close(array $order): array
    {
        throw new PaymentException('LakalaPayment::close 暂未实现', 402);
    }

    public function refund(array $order): array
    {
        throw new PaymentException('LakalaPayment::refund 暂未实现', 402);
    }

    public function notify(Request $request): array
    {
        throw new PaymentException('LakalaPayment::notify 暂未实现', 402);
    }
    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    public function notifyFail(): string|Response
    {
        return 'fail';
    }
}
