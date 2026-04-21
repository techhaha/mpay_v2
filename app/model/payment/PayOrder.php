<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付单模型。
 * 表示一次具体支付尝试，包含通道、状态、手续费快照和回调状态。
 */
class PayOrder extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_pay_order';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'pay_no',
        'biz_no',
        'trace_no',
        'merchant_id',
        'merchant_group_id',
        'poll_group_id',
        'attempt_no',
        'channel_id',
        'pay_type_id',
        'plugin_code',
        'channel_type',
        'channel_mode',
        'pay_amount',
        'fee_rate_bp_snapshot',
        'split_rate_bp_snapshot',
        'fee_estimated_amount',
        'fee_actual_amount',
        'status',
        'fee_status',
        'settlement_status',
        'channel_request_no',
        'channel_order_no',
        'channel_trade_no',
        'channel_error_code',
        'channel_error_msg',
        'request_at',
        'paid_at',
        'expire_at',
        'closed_at',
        'failed_at',
        'timeout_at',
        'callback_status',
        'callback_times',
        'ext_json',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_group_id' => 'integer',
        'poll_group_id' => 'integer',
        'attempt_no' => 'integer',
        'channel_id' => 'integer',
        'pay_type_id' => 'integer',
        'channel_type' => 'integer',
        'channel_mode' => 'integer',
        'pay_amount' => 'integer',
        'fee_rate_bp_snapshot' => 'integer',
        'split_rate_bp_snapshot' => 'integer',
        'fee_estimated_amount' => 'integer',
        'fee_actual_amount' => 'integer',
        'status' => 'integer',
        'fee_status' => 'integer',
        'settlement_status' => 'integer',
        'request_at' => 'datetime',
        'paid_at' => 'datetime',
        'expire_at' => 'datetime',
        'closed_at' => 'datetime',
        'failed_at' => 'datetime',
        'timeout_at' => 'datetime',
        'callback_status' => 'integer',
        'callback_times' => 'integer',
        'ext_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




