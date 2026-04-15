<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 渠道通知日志模型。
 * 用于记录异步通知、查单请求和去重处理结果。
 */
class ChannelNotifyLog extends BaseModel
{
    protected $table = 'ma_channel_notify_log';

    protected $fillable = [
        'notify_no',
        'channel_id',
        'notify_type',
        'biz_no',
        'pay_no',
        'channel_request_no',
        'channel_trade_no',
        'raw_payload',
        'verify_status',
        'process_status',
        'retry_count',
        'next_retry_at',
        'last_error',
    ];

    protected $hidden = [
        'raw_payload',
    ];

    protected $casts = [
        'channel_id' => 'integer',
        'notify_type' => 'integer',
        'verify_status' => 'integer',
        'process_status' => 'integer',
        'retry_count' => 'integer',
        'next_retry_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


