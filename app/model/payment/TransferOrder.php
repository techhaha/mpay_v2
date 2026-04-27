<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 转账单模型。
 */
class TransferOrder extends BaseModel
{
    protected $table = 'ma_transfer_order';

    protected $fillable = [
        'biz_no',
        'trace_no',
        'merchant_id',
        'merchant_group_id',
        'out_biz_no',
        'type',
        'account',
        'name',
        'amount',
        'cost_amount',
        'remark',
        'bookid',
        'channel_id',
        'channel_request_no',
        'channel_order_no',
        'channel_trade_no',
        'channel_error_code',
        'channel_error_msg',
        'status',
        'request_at',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'ext_json',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_group_id' => 'integer',
        'channel_id' => 'integer',
        'amount' => 'integer',
        'cost_amount' => 'integer',
        'status' => 'integer',
        'request_at' => 'datetime',
        'processing_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
        'ext_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

