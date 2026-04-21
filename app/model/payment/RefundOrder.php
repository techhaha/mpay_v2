<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 退款单模型。
 * 当前按整单全额退款设计，因此同一支付单只允许一张退款单。
 */
class RefundOrder extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_refund_order';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'refund_no',
        'merchant_id',
        'merchant_group_id',
        'biz_no',
        'trace_no',
        'pay_no',
        'merchant_refund_no',
        'channel_id',
        'refund_amount',
        'fee_reverse_amount',
        'status',
        'channel_request_no',
        'channel_refund_no',
        'reason',
        'request_at',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'retry_count',
        'last_error',
        'ext_json',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_group_id' => 'integer',
        'channel_id' => 'integer',
        'refund_amount' => 'integer',
        'fee_reverse_amount' => 'integer',
        'status' => 'integer',
        'request_at' => 'datetime',
        'processing_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
        'retry_count' => 'integer',
        'ext_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




