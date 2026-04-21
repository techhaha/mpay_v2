<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 渠道通知日志模型。
 * 用于记录异步通知、查单请求和去重状态。
 */
class ChannelNotifyLog extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_channel_notify_log';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
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

    /**
     * 隐藏字段
     *
     * @var mixed
     */
    protected $hidden = [
        'raw_payload',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
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



