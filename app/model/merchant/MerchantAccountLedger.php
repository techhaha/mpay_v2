<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户余额流水模型。
 * 所有余额变动都必须落流水，便于审计、对账和幂等控制。
 */
class MerchantAccountLedger extends BaseModel
{
    protected $table = 'ma_merchant_account_ledger';

    public $timestamps = false;

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
        'ext_json',
    ];

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
        'ext_json' => 'array',
        'created_at' => 'datetime',
    ];
}


