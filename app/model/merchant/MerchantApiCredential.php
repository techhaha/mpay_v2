<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户对外接口凭证模型。
 * 保存商户接口凭证、签名类型、启用状态和最近使用时间。
 */
class MerchantApiCredential extends BaseModel
{
    protected $table = 'ma_merchant_api_credential';

    protected $fillable = [
        'merchant_id',
        'sign_type',
        'api_key',
        'status',
        'last_used_at',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'sign_type' => 'integer',
        'status' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
