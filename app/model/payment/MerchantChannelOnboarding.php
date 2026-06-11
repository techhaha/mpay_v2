<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 商户支付渠道进件申请模型。
 */
class MerchantChannelOnboarding extends BaseModel
{
    protected $table = 'ma_merchant_channel_onboarding';

    protected $fillable = [
        'onboarding_no',
        'merchant_id',
        'merchant_no',
        'onboarding_config_id',
        'plugin_code',
        'subject_type',
        'apply_products',
        'form_data',
        'file_assets',
        'rate_config',
        'status',
        'platform_audit_msg',
        'upstream_apply_id',
        'upstream_contract_id',
        'upstream_merchant_no',
        'upstream_terminal_no',
        'upstream_status',
        'upstream_message',
        'submitted_at',
        'reviewed_at',
        'upstream_submitted_at',
        'signed_at',
        'cancelled_at',
        'remark',
    ];

    protected $casts = [
        'merchant_id' => 'integer',
        'onboarding_config_id' => 'integer',
        'apply_products' => 'array',
        'form_data' => 'array',
        'file_assets' => 'array',
        'rate_config' => 'array',
        'status' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'upstream_submitted_at' => 'datetime',
        'signed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
