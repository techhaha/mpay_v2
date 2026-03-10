<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付回调日志模型
 *
 * 对应表：ma_pay_callback_log
 */
class PaymentCallbackLog extends BaseModel
{
    protected $table = 'ma_pay_callback_log';

    protected $fillable = [
        'order_id',
        'channel_id',
        'callback_type',
        'request_data',
        'verify_status',
        'process_status',
        'process_result',
    ];

    public $timestamps = true;

    protected $casts = [
        'channel_id' => 'integer',
        'verify_status' => 'integer',
        'process_status' => 'integer',
    ];
}
