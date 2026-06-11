<?php

namespace app\model\payment;

use app\common\base\BaseModel;

/**
 * 商户支付渠道进件日志模型。
 */
class MerchantChannelOnboardingLog extends BaseModel
{
    protected $table = 'ma_merchant_channel_onboarding_log';

    protected $fillable = [
        'onboarding_id',
        'onboarding_no',
        'merchant_id',
        'onboarding_config_id',
        'plugin_code',
        'action',
        'operator_type',
        'operator_id',
        'operator_name',
        'request_no',
        'upstream_apply_id',
        'upstream_status',
        'result_status',
        'result_code',
        'message',
        'summary',
    ];

    protected $casts = [
        'onboarding_id' => 'integer',
        'merchant_id' => 'integer',
        'onboarding_config_id' => 'integer',
        'operator_id' => 'integer',
        'summary' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
