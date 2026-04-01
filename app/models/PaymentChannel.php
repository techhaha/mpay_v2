<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 支付通道模型
 *
 * 对应表：ma_pay_channel
 */
class PaymentChannel extends BaseModel
{
    protected $table = 'ma_pay_channel';

    protected $fillable = [
        'mer_id',
        'app_id',
        'chan_code',
        'chan_name',
        'plugin_code',
        'pay_type_id',
        'config',
        'split_ratio',
        'chan_cost',
        'chan_mode',
        'daily_limit',
        'daily_cnt',
        'min_amount',
        'max_amount',
        'status',
        'sort',
    ];

    public $timestamps = true;

    protected $appends = ['merchant_id', 'merchant_app_id', 'method_id', 'config_json'];

    protected $casts = [
        'mer_id' => 'integer',
        'app_id' => 'integer',
        'pay_type_id' => 'integer',
        'config' => 'array',
        'split_ratio' => 'decimal:2',
        'chan_cost' => 'decimal:2',
        'chan_mode' => 'integer',
        'daily_limit' => 'decimal:2',
        'daily_cnt' => 'integer',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'status' => 'integer',
        'sort' => 'integer',
    ];

    public function getConfigArray(): array
    {
        return $this->config ?? [];
    }

    public function getEnabledProducts(): array
    {
        $config = $this->getConfigArray();
        return $config['enabled_products'] ?? [];
    }

    public function getMerchantIdAttribute()
    {
        return $this->attributes['mer_id'] ?? null;
    }

    public function setMerchantIdAttribute($value): void
    {
        $this->attributes['mer_id'] = (int)$value;
    }

    public function getMerchantAppIdAttribute()
    {
        return $this->attributes['app_id'] ?? null;
    }

    public function setMerchantAppIdAttribute($value): void
    {
        $this->attributes['app_id'] = (int)$value;
    }

    public function getMethodIdAttribute()
    {
        return $this->attributes['pay_type_id'] ?? null;
    }

    public function setMethodIdAttribute($value): void
    {
        $this->attributes['pay_type_id'] = (int)$value;
    }

    public function getConfigJsonAttribute()
    {
        return $this->attributes['config'] ?? [];
    }

    public function setConfigJsonAttribute($value): void
    {
        $this->attributes['config'] = $value;
    }
}
