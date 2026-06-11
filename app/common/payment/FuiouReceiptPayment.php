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
 * 富友二维码牌网页流水监听插件。
 *
 * 当前平台流水无法稳定提供付款备注，后台配置仅开放金额变动模式。
 * 该类只声明插件身份和平台能力，通用收款逻辑由 WebReceiptPaymentTrait 提供。
 */
class FuiouReceiptPayment extends BasePayment implements PaymentInterface, PayPluginInterface, ChannelNotifyPayloadInterface
{
    use WebReceiptPaymentTrait;

    /**
     * 插件基础信息和网页码牌能力。
     *
     * 不在这里写 `config_schema`，由 WebReceiptPaymentTrait 统一生成通用码牌表单。
     * `receipt_supports_remark=false` 会隐藏付款备注模式，只保留金额变动。
     *
     * @var array<string, mixed>
     */
    protected array $paymentInfo = [
        'code' => 'fuiou_receipt',
        'name' => '富友码牌收款',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'receipt_supports_remark' => false,
    ];
}
