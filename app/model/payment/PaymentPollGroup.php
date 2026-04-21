<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付轮询组模型。
 * 用于将支付方式映射到一组可路由通道。
 */
class PaymentPollGroup extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_payment_poll_group';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'group_name',
        'pay_type_id',
        'route_mode',
        'status',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'pay_type_id' => 'integer',
        'route_mode' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}



