<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户余额账户模型。
 * 仅保存可提现余额、冻结余额和时间戳。
 */
class MerchantAccount extends BaseModel
{
    protected $table = 'ma_merchant_account';

    protected $fillable = [
        'merchant_id',
        'available_balance',
        'frozen_balance',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'available_balance' => 'integer',
        'frozen_balance' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
