<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 轮询组与通道编排模型。
 * 定义组内通道顺序、权重和默认通道。
 */
class PaymentPollGroupChannel extends BaseModel
{
    protected $table = 'ma_payment_poll_group_channel';

    protected $fillable = [
        'poll_group_id',
        'channel_id',
        'sort_no',
        'weight',
        'is_default',
        'status',
        'remark',
    ];

    protected $casts = [
        'poll_group_id' => 'integer',
        'channel_id' => 'integer',
        'sort_no' => 'integer',
        'weight' => 'integer',
        'is_default' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


