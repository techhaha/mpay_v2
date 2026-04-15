<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户基础资料模型。
 * 仅承载商户身份、联系信息、结算信息和启停状态，不承载资金余额。
 */
class Merchant extends BaseModel
{
    protected $table = 'ma_merchant';

    protected $fillable = [
        'merchant_no',
        'password_hash',
        'merchant_name',
        'merchant_short_name',
        'merchant_type',
        'group_id',
        'risk_level',
        'contact_name',
        'contact_phone',
        'contact_email',
        'settlement_account_name',
        'settlement_account_no',
        'settlement_bank_name',
        'settlement_bank_branch',
        'status',
        'last_login_at',
        'last_login_ip',
        'password_updated_at',
        'remark',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'merchant_type' => 'integer',
        'group_id' => 'integer',
        'risk_level' => 'integer',
        'status' => 'integer',
        'last_login_at' => 'datetime',
        'password_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
