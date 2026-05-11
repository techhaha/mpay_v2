<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户资金冻结明细模型。
 *
 * 一条记录代表一笔冻结占用，可关联支付单，也可作为后续人工指定金额冻结的载体。
 */
class MerchantFundFreeze extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant_fund_freeze';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'freeze_no',
        'merchant_id',
        'biz_no',
        'pay_no',
        'trace_no',
        'freeze_type',
        'freeze_amount',
        'remaining_amount',
        'status',
        'reason',
        'admin_id',
        'available_at',
        'frozen_at',
        'release_reason',
        'released_by',
        'released_at',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'freeze_type' => 'integer',
        'freeze_amount' => 'integer',
        'remaining_amount' => 'integer',
        'status' => 'integer',
        'admin_id' => 'integer',
        'available_at' => 'datetime',
        'frozen_at' => 'datetime',
        'released_by' => 'integer',
        'released_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
