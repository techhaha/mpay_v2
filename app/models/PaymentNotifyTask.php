<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 商户通知任务模型
 *
 * 对应表：ma_notify_task
 */
class PaymentNotifyTask extends BaseModel
{
    protected $table = 'ma_notify_task';

    protected $fillable = [
        'order_id',
        'merchant_id',
        'merchant_app_id',
        'notify_url',
        'notify_data',
        'status',
        'retry_cnt',
        'next_retry_at',
        'last_notify_at',
        'last_response',
    ];

    public $timestamps = true;

    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_app_id' => 'integer',
        'retry_cnt' => 'integer',
        'next_retry_at' => 'datetime',
        'last_notify_at' => 'datetime',
    ];

    const STATUS_PENDING = 'PENDING';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAIL = 'FAIL';
}
