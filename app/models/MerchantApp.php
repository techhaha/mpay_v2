<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 商户应用模型
 */
class MerchantApp extends BaseModel
{
    protected $table = 'ma_pay_app';
    
    protected $fillable = [
        'mer_id',
        'api_type',
        'app_code',
        'app_secret',
        'app_name',
        'status',
        'remark',
        'package_code',
        'notify_url',
        'return_url',
        'callback_mode',
        'sign_type',
        'order_expire_minutes',
        'callback_retry_limit',
        'ip_whitelist',
        'amount_min',
        'amount_max',
        'daily_limit',
        'notify_enabled',
        'extra',
    ];
    
    public $timestamps = true;

    protected $appends = ['merchant_id', 'app_id'];

    protected $casts = [
        'mer_id' => 'integer',
        'order_expire_minutes' => 'integer',
        'callback_retry_limit' => 'integer',
        'amount_min' => 'decimal:2',
        'amount_max' => 'decimal:2',
        'daily_limit' => 'decimal:2',
        'notify_enabled' => 'integer',
        'status' => 'integer',
        'extra' => 'array',
    ];

    public function getMerchantIdAttribute()
    {
        return $this->attributes['mer_id'] ?? null;
    }

    public function setMerchantIdAttribute($value): void
    {
        $this->attributes['mer_id'] = (int)$value;
    }

    public function getAppIdAttribute()
    {
        return $this->attributes['app_code'] ?? null;
    }

    public function setAppIdAttribute($value): void
    {
        $this->attributes['app_code'] = (string)$value;
    }
}

