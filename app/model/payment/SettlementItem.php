<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 清算明细模型。
 * 用于记录清算单下每笔支付订单的入账结果。
 */
class SettlementItem extends BaseModel
{
    protected $table = 'ma_settlement_item';

    protected $fillable = [
        'settle_no',
        'merchant_id',
        'merchant_group_id',
        'channel_id',
        'pay_no',
        'refund_no',
        'pay_amount',
        'fee_amount',
        'refund_amount',
        'fee_reverse_amount',
        'net_amount',
        'item_status',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_group_id' => 'integer',
        'channel_id' => 'integer',
        'pay_amount' => 'integer',
        'fee_amount' => 'integer',
        'refund_amount' => 'integer',
        'fee_reverse_amount' => 'integer',
        'net_amount' => 'integer',
        'item_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


