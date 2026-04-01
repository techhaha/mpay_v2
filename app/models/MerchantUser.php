<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 商户后台用户模型
 */
class MerchantUser extends BaseModel
{
    protected $table = 'ma_mer_user';

    protected $fillable = [
        'mer_id',
        'username',
        'password',
        'nick_name',
        'avatar',
        'mobile',
        'email',
        'role_code',
        'is_owner',
        'status',
        'login_ip',
        'login_at',
    ];

    public $timestamps = true;

    protected $appends = ['merchant_id'];

    protected $casts = [
        'mer_id' => 'integer',
        'is_owner' => 'integer',
        'status' => 'integer',
        'login_at' => 'datetime',
    ];

    protected $hidden = ['password'];

    public function getMerchantIdAttribute()
    {
        return $this->attributes['mer_id'] ?? null;
    }

    public function setMerchantIdAttribute($value): void
    {
        $this->attributes['mer_id'] = (int)$value;
    }
}
