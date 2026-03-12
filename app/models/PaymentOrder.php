<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付订单模型
 *
 * 对应表：ma_pay_order
 */
class PaymentOrder extends BaseModel
{
    protected $table = 'ma_pay_order';

    protected $fillable = [
        'order_id',
        'merchant_id',
        'merchant_app_id',
        'mch_order_no',
        'method_id',
        'channel_id',
        'amount',
        'real_amount',
        'fee',
        'currency',
        'subject',
        'body',
        'status',
        'chan_order_no',
        'chan_trade_no',
        'pay_at',
        'expire_at',
        'client_ip',
        'notify_stat',
        'notify_cnt',
        'extra',
    ];

    public $timestamps = true;

    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_app_id' => 'integer',
        'method_id' => 'integer',
        'channel_id' => 'integer',
        'amount' => 'decimal:2',
        'real_amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'status' => 'integer',
        'notify_stat' => 'integer',
        'notify_cnt' => 'integer',
        'extra' => 'array',
        'pay_at' => 'datetime',
        'expire_at' => 'datetime',
    ];

    /* 订单状态 */
    const STATUS_PENDING = 0; // 待支付
    const STATUS_SUCCESS = 1; // 支付成功
    const STATUS_FAIL = 2; // 支付失败
    const STATUS_CLOSED = 3; // 已关闭
}
