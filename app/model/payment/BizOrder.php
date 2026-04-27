<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 业务订单模型。
 * 表示商户业务侧原始订单，只保留业务事实与收银台恢复所需字段。
 */
class BizOrder extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_biz_order';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'biz_no',
        'trace_no',
        'merchant_id',
        'merchant_order_no',
        'subject',
        'body',
        'notify_url',
        'return_url',
        'client_ip',
        'device',
        'order_amount',
        'paid_amount',
        'refund_amount',
        'status',
        'active_pay_no',
        'attempt_count',
        'expire_at',
        'paid_at',
        'closed_at',
        'failed_at',
        'timeout_at',
        'ext_json',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'notify_url' => 'string',
        'return_url' => 'string',
        'client_ip' => 'string',
        'device' => 'string',
        'order_amount' => 'integer',
        'paid_amount' => 'integer',
        'refund_amount' => 'integer',
        'status' => 'integer',
        'attempt_count' => 'integer',
        'expire_at' => 'datetime',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
        'failed_at' => 'datetime',
        'timeout_at' => 'datetime',
        'ext_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

