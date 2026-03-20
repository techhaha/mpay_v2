<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 回调幂等收件箱
 *
 * 对应表：ma_callback_inbox
 */
class CallbackInbox extends BaseModel
{
    protected $table = 'ma_callback_inbox';

    protected $fillable = [
        'event_key',
        'plugin_code',
        'order_id',
        'chan_trade_no',
        'payload',
        'process_status',
        'processed_at',
    ];

    public $timestamps = true;

    protected $casts = [
        'payload' => 'array',
        'process_status' => 'integer',
        'processed_at' => 'datetime',
    ];
}

