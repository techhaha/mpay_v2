<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 支付回调日志模型。
 * 用于记录同步和异步回调原始报文和处理结果。
 */
class PayCallbackLog extends BaseModel
{
    protected $table = 'ma_pay_callback_log';

    public $timestamps = false;

    protected $fillable = [
        'pay_no',
        'channel_id',
        'callback_type',
        'request_data',
        'verify_status',
        'process_status',
        'process_result',
    ];

    protected $hidden = [
        'request_data',
        'process_result',
    ];

    protected $casts = [
        'channel_id' => 'integer',
        'callback_type' => 'integer',
        'verify_status' => 'integer',
        'process_status' => 'integer',
        'created_at' => 'datetime',
    ];
}


