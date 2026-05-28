<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\trait\WebReceiptPaymentTrait;

/**
 * 旺铺二维码牌网页流水监听插件。
 *
 * 该类只声明插件身份和平台能力。二维码承接、金额变动、备注校验、
 * 流水定位订单等通用行为由 WebReceiptPaymentTrait 提供。
 */
class WangpuReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * 不在这里写 `config_schema`，由 WebReceiptPaymentTrait 统一生成通用码牌表单。
     * `receipt_supports_remark=true` 表示后台允许选择“付款备注”模式。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'wangpu_receipt',
        'name' => '旺铺码牌收款',
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_supports_remark' => true,
    ];
}
