<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginTypeConstant;
use app\common\interface\ChannelNotifyPayloadInterface;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\common\trait\WebReceiptPaymentTrait;

/**
 * 星驿付收款单网页流水监听插件。
 *
 * 该类只声明插件身份和平台能力。二维码承接、金额变动、备注校验、
 * 流水定位订单等通用行为由 WebReceiptPaymentTrait 提供。
 */
class PostarReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'postar_receipt',
        'name' => '星驿付收款单收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay', 'unionpay'],
        'transfer_types' => [],
        'receipt_supports_remark' => true,
    ];
}
