<?php

namespace app\model\merchant;

use app\common\base\BaseModel;

/**
 * 商户策略模型。
 * 维护商户级覆盖策略，例如结算周期、自动处理和路由策略。
 */
class MerchantPolicy extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_merchant_policy';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'merchant_id',
        'settlement_cycle_override',
        'auto_payout',
        'min_settlement_amount',
        'retry_policy_json',
        'route_policy_json',
        'fee_rule_override_json',
        'risk_policy_json',
        'remark',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'merchant_id' => 'integer',
        'settlement_cycle_override' => 'integer',
        'auto_payout' => 'integer',
        'min_settlement_amount' => 'integer',
        'retry_policy_json' => 'array',
        'route_policy_json' => 'array',
        'fee_rule_override_json' => 'array',
        'risk_policy_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




