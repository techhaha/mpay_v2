<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 商户端在线签约进件校验器。
 */
class OnboardingApplicationValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'onboarding_config_id' => 'sometimes|integer|min:1',
        'subject_type' => 'sometimes|string|max:32',
        'apply_products' => 'sometimes|array',
        'form_data' => 'nullable|array',
        'file_assets' => 'nullable|array',
        'remark' => 'nullable|string|max:500',
        'submit' => 'sometimes|integer|in:0,1',
        'status' => 'sometimes|integer|min:0|max:20',
        'card_no' => 'sometimes|string|min:8|max:32',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '进件申请',
        'keyword' => '关键字',
        'onboarding_config_id' => '进件渠道',
        'subject_type' => '主体类型',
        'apply_products' => '申请产品',
        'form_data' => '进件资料',
        'file_assets' => '文件资料',
        'remark' => '备注',
        'submit' => '是否提交',
        'status' => '状态',
        'card_no' => '银行卡号',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'onboarding_config_id', 'status', 'page', 'page_size'],
        'store' => ['onboarding_config_id', 'subject_type', 'apply_products', 'form_data', 'file_assets', 'remark', 'submit'],
        'show' => ['id'],
        'action' => ['id'],
        'cardBin' => ['id', 'card_no'],
    ];

    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'onboarding_config_id' => 'required|integer|min:1',
                'subject_type' => 'required|string|max:32',
                'apply_products' => 'required|array',
            ]),
            'show', 'action' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            'cardBin' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'card_no' => 'required|string|min:8|max:32',
            ]),
            default => $rules,
        };
    }
}
