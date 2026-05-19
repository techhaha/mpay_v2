<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 支付订单后台操作日志模型。
 *
 * 记录管理后台针对支付单发起的主动查单、补单、退款、冻结等操作。
 */
class PayOrderOperationLog extends BaseModel
{
    /**
     * 数据表名。
     *
     * @var mixed
     */
    protected $table = 'ma_pay_order_operation_log';

    /**
     * 是否自动维护时间戳。
     *
     * @var mixed
     */
    public $timestamps = false;

    /**
     * 可批量赋值字段。
     *
     * @var mixed
     */
    protected $fillable = [
        'pay_no',
        'biz_no',
        'action',
        'admin_id',
        'reason',
        'result_status',
        'result_message',
        'result_payload',
        'created_at',
    ];

    /**
     * 隐藏字段。
     *
     * @var mixed
     */
    protected $hidden = [
        'result_payload',
    ];

    /**
     * 字段类型转换配置。
     *
     * @var mixed
     */
    protected $casts = [
        'admin_id' => 'integer',
        'result_payload' => 'array',
        'created_at' => 'datetime',
    ];
}
