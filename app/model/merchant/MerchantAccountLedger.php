<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户余额流水模型。
 * 所有余额变动都必须落流水，便于审计、对账和幂等控制。
 */
class MerchantAccountLedger extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant_account_ledger';

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
        'ledger_no',
        'merchant_id',
        'biz_type',
        'biz_no',
        'trace_no',
        'event_type',
        'direction',
        'amount',
        'available_before',
        'available_after',
        'frozen_before',
        'frozen_after',
        'idempotency_key',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'biz_type' => 'integer',
        'event_type' => 'integer',
        'direction' => 'integer',
        'amount' => 'integer',
        'available_before' => 'integer',
        'available_after' => 'integer',
        'frozen_before' => 'integer',
        'frozen_after' => 'integer',
        'created_at' => 'datetime',
    ];
}



