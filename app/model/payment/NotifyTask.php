<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 商户通知任务模型。
 * 保存通知重试队列、最后一次响应和下一次重试时间。
 */
class NotifyTask extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_notify_task';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'notify_no',
        'event_type',
        'ref_no',
        'merchant_id',
        'merchant_group_id',
        'biz_no',
        'pay_no',
        'notify_url',
        'notify_data',
        'status',
        'retry_count',
        'next_retry_at',
        'last_notify_at',
        'last_response',
    ];

    /**
     * 隐藏字段
     *
     * @var mixed
     */
    protected $hidden = [
        'notify_data',
        'last_response',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'event_type' => 'string',
        'ref_no' => 'string',
        'merchant_id' => 'integer',
        'merchant_group_id' => 'integer',
        'notify_data' => 'array',
        'status' => 'integer',
        'retry_count' => 'integer',
        'next_retry_at' => 'datetime',
        'last_notify_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}


