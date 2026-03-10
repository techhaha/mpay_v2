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
        'merchant_id',
        'merchant_app_id',
        'chan_code',
        'chan_name',
        'plugin_code',
        'method_id',
        'config_json',
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

    protected $casts = [
        'merchant_id' => 'integer',
        'merchant_app_id' => 'integer',
        'method_id' => 'integer',
        'config_json' => 'array',
        'split_ratio' => 'decimal:2',
        'chan_cost' => 'decimal:2',
        'daily_limit' => 'decimal:2',
        'daily_cnt' => 'integer',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'status' => 'integer',
        'sort' => 'integer',
    ];

    public function getConfigArray(): array
    {
        return $this->config_json ?? [];
    }

    public function getEnabledProducts(): array
    {
        $config = $this->getConfigArray();
        return $config['enabled_products'] ?? [];
    }
}
