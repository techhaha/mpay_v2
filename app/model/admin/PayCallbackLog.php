<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 支付回调日志模型。
 * 用于记录同步和异步回调原始报文和处理状态。
 */
class PayCallbackLog extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_pay_callback_log';

    /**
     * 是否自动维护时间戳
     *
     * @var mixed
     */
    public $timestamps = false;

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'pay_no',
        'channel_id',
        'callback_type',
        'request_data',
        'request_hash',
        'verify_status',
        'process_status',
        'process_result',
        'created_at',
    ];

    /**
     * 隐藏字段
     *
     * @var mixed
     */
    protected $hidden = [
        'request_data',
        'process_result',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'channel_id' => 'integer',
        'callback_type' => 'integer',
        'request_data' => 'array',
        'request_hash' => 'string',
        'verify_status' => 'integer',
        'process_status' => 'integer',
        'process_result' => 'array',
        'created_at' => 'datetime',
    ];
}

