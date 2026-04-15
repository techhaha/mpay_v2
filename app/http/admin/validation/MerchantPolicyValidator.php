<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户策略校验器。
 */
class MerchantPolicyValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'merchant_id' => 'sometimes|integer|min:1|exists:ma_merchant,id',
        'keyword' => 'sometimes|string|max:128',
        'group_id' => 'sometimes|integer|min:1|exists:ma_merchant_group,id',
        'has_policy' => 'sometimes|integer|in:0,1',
        'settlement_cycle_override' => 'sometimes|integer|in:0,1,2,3,4',
        'auto_payout' => 'sometimes|integer|in:0,1',
        'min_settlement_amount' => 'sometimes|integer|min:0',
        'retry_policy_json' => 'sometimes|array',
        'route_policy_json' => 'sometimes|array',
        'fee_rule_override_json' => 'sometimes|array',
        'risk_policy_json' => 'sometimes|array',
        'remark' => 'sometimes|string|max:500',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '策略ID',
        'merchant_id' => '所属商户',
        'keyword' => '关键字',
        'group_id' => '商户分组',
        'has_policy' => '策略状态',
        'settlement_cycle_override' => '结算周期覆盖',
        'auto_payout' => '自动处理',
        'min_settlement_amount' => '最小结算金额',
        'retry_policy_json' => '重试策略',
        'route_policy_json' => '路由策略',
        'fee_rule_override_json' => '费率覆盖策略',
        'risk_policy_json' => '风控策略',
        'remark' => '备注',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'group_id', 'has_policy', 'settlement_cycle_override', 'auto_payout', 'page', 'page_size'],
        'show' => ['merchant_id'],
        'store' => [
            'merchant_id',
            'settlement_cycle_override',
            'auto_payout',
            'min_settlement_amount',
            'retry_policy_json',
            'route_policy_json',
            'fee_rule_override_json',
            'risk_policy_json',
            'remark',
        ],
        'update' => [
            'merchant_id',
            'settlement_cycle_override',
            'auto_payout',
            'min_settlement_amount',
            'retry_policy_json',
            'route_policy_json',
            'fee_rule_override_json',
            'risk_policy_json',
            'remark',
        ],
    ];

    public function sceneStore(): static
    {
        return $this->appendRules([
            'merchant_id' => 'required|integer|min:1|exists:ma_merchant,id',
            'settlement_cycle_override' => 'required|integer|in:0,1,2,3,4',
            'auto_payout' => 'required|integer|in:0,1',
            'min_settlement_amount' => 'required|integer|min:0',
        ]);
    }

    public function sceneUpdate(): static
    {
        return $this->sceneStore();
    }
}
