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
     * 星驿付收款单流水接口仍需要收款单 ID 和部门 ID，因此通过
     * `receiptExtraConfigSchema()` 补充平台专属配置。
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

    /**
     * 星驿付收款单查询接口需要的业务参数。
     *
     * 登录地址、流水接口和验证码策略仍在 Python watcher 中维护。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function receiptExtraConfigSchema(): array
    {
        return [
            [
                'type' => 'input',
                'field' => 'postar_ren_page_id',
                'title' => '收款单ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入星驿付收款单 renPageId',
                ],
                'validate' => [
                    ['required' => true, 'message' => '收款单ID不能为空'],
                ],
            ],
            [
                'type' => 'input',
                'field' => 'postar_dept_id',
                'title' => '部门ID',
                'value' => '',
                'props' => [
                    'placeholder' => '请输入星驿付收款单 deptId',
                ],
                'validate' => [
                    ['required' => true, 'message' => '部门ID不能为空'],
                ],
            ],
        ];
    }
}
