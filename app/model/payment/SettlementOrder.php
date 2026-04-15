<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 清算单模型。
 * 平台代收链路清算成功后将资金转入可提现余额。
 */
class SettlementOrder extends BaseModel
{
    protected $table = 'ma_settlement_order';

    protected $fillable = [
        'settle_no',
        'trace_no',
        'merchant_id',
        'merchant_group_id',
        'channel_id',
        'cycle_type',
        'cycle_key',
        'status',
        'gross_amount',
        'fee_amount',
        'refund_amount',
        'fee_reverse_amount',
        'net_amount',
        'accounted_amount',
        'generated_at',
        'accounted_at',
        'completed_at',
        'failed_at',
        'fail_reason',
        'ext_json',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_group_id' => 'integer',
        'channel_id' => 'integer',
        'cycle_type' => 'integer',
        'status' => 'integer',
        'gross_amount' => 'integer',
        'fee_amount' => 'integer',
        'refund_amount' => 'integer',
        'fee_reverse_amount' => 'integer',
        'net_amount' => 'integer',
        'accounted_amount' => 'integer',
        'generated_at' => 'datetime',
        'accounted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'ext_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


