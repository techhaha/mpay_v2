<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户基础资料模型。
 * 仅承载商户身份、联系信息、结算信息和启停状态，不承载资金余额。
 */
class Merchant extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
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
        'pay_status',
        'settle_status',
        'settle_type',
        'status',
        'last_login_at',
        'last_login_ip',
        'password_updated_at',
        'remark',
    ];

    /**
     * 隐藏字段
     *
     * @var mixed
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_type' => 'integer',
        'group_id' => 'integer',
        'risk_level' => 'integer',
        'pay_status' => 'integer',
        'settle_status' => 'integer',
        'settle_type' => 'integer',
        'status' => 'integer',
        'last_login_at' => 'datetime',
        'password_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

