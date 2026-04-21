<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 清算创建参数校验器。
 *
 * 用于校验清算单和清算明细的创建参数。
 */
class SettlementCreateValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'settle_no' => 'sometimes|string|min:1|max:64',
        'merchant_id' => 'required|integer|min:1|exists:ma_merchant,id',
        'merchant_group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
        'channel_id' => 'required|integer|min:1|exists:ma_payment_channel,id',
        'cycle_type' => 'required|integer|min:0',
        'cycle_key' => 'required|string|min:1|max:64',
        'status' => 'sometimes|integer|min:0',
        'generated_at' => 'nullable|date_format:Y-m-d H:i:s',
        'accounted_amount' => 'nullable|integer|min:0',
        'gross_amount' => 'nullable|integer|min:0',
        'fee_amount' => 'nullable|integer|min:0',
        'refund_amount' => 'nullable|integer|min:0',
        'fee_reverse_amount' => 'nullable|integer|min:0',
        'net_amount' => 'nullable|integer|min:0',
        'ext_json' => 'nullable|array',
        'items' => 'nullable|array',
        'items.*.pay_no' => 'sometimes|string|min:1|max:64',
        'items.*.refund_no' => 'sometimes|string|min:1|max:64',
        'items.*.pay_amount' => 'sometimes|integer|min:0',
        'items.*.fee_amount' => 'sometimes|integer|min:0',
        'items.*.refund_amount' => 'sometimes|integer|min:0',
        'items.*.fee_reverse_amount' => 'sometimes|integer|min:0',
        'items.*.net_amount' => 'sometimes|integer|min:0',
        'items.*.item_status' => 'sometimes|integer|min:0',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'settle_no' => '清算单号',
        'merchant_id' => '商户ID',
        'merchant_group_id' => '商户分组ID',
        'channel_id' => '通道ID',
        'cycle_type' => '结算周期类型',
        'cycle_key' => '结算周期键',
        'status' => '清算单状态',
        'generated_at' => '生成时间',
        'accounted_amount' => '入账金额',
        'gross_amount' => '交易总额',
        'fee_amount' => '手续费',
        'refund_amount' => '退款金额',
        'fee_reverse_amount' => '手续费冲回',
        'net_amount' => '净额',
        'ext_json' => '扩展信息',
        'items' => '清算明细',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'store' => ['settle_no', 'merchant_id', 'merchant_group_id', 'channel_id', 'cycle_type', 'cycle_key', 'status', 'generated_at', 'accounted_amount', 'gross_amount', 'fee_amount', 'refund_amount', 'fee_reverse_amount', 'net_amount', 'ext_json', 'items', 'items.*.pay_no', 'items.*.refund_no', 'items.*.pay_amount', 'items.*.fee_amount', 'items.*.refund_amount', 'items.*.fee_reverse_amount', 'items.*.net_amount', 'items.*.item_status'],
    ];
}


