<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 支付插件进件配置模型。
 */
class PaymentPluginOnboardingConf extends BaseModel
{
    protected $table = 'ma_payment_plugin_onboarding_conf';

    protected $fillable = [
        'plugin_code',
        'name',
        'config',
        'subject_types',
        'apply_products',
        'rate_config',
        'merchant_visible',
        'status',
        'sort_no',
        'description',
        'remark',
    ];

    protected $casts = [
        'config' => 'array',
        'subject_types' => 'array',
        'apply_products' => 'array',
        'rate_config' => 'array',
        'merchant_visible' => 'integer',
        'status' => 'integer',
        'sort_no' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
