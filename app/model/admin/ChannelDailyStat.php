<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 通道日统计模型。
 * 用于路由健康度、成功率和耗时统计。
 */
class ChannelDailyStat extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_channel_daily_stat';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'merchant_id',
        'merchant_group_id',
        'channel_id',
        'stat_date',
        'pay_success_count',
        'pay_fail_count',
        'pay_amount',
        'refund_count',
        'refund_amount',
        'avg_latency_ms',
        'success_rate_bp',
        'health_score',
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
        'pay_success_count' => 'integer',
        'pay_fail_count' => 'integer',
        'pay_amount' => 'integer',
        'refund_count' => 'integer',
        'refund_amount' => 'integer',
        'avg_latency_ms' => 'integer',
        'success_rate_bp' => 'integer',
        'health_score' => 'integer',
        'stat_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




