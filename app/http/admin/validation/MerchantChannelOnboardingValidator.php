<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户支付渠道进件申请参数校验器。
 */
class MerchantChannelOnboardingValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'onboarding_config_id' => 'sometimes|integer|min:1',
        'plugin_code' => 'sometimes|string|alpha_dash|min:2|max:32',
        'subject_type' => 'sometimes|string|max:32',
        'apply_products' => 'sometimes|array',
        'form_data' => 'nullable|array',
        'file_assets' => 'nullable|array',
        'remark' => 'nullable|string|max:500',
        'submit' => 'sometimes|integer|in:0,1',
        'status' => 'sometimes|integer|min:0|max:20',
        'approved' => 'sometimes|integer|in:0,1',
        'message' => 'nullable|string|max:1000',
        'upstream_apply_id' => 'nullable|string|max:128',
        'upstream_contract_id' => 'nullable|string|max:128',
        'upstream_merchant_no' => 'nullable|string|max:128',
        'upstream_terminal_no' => 'nullable|string|max:128',
        'upstream_status' => 'nullable|string|max:64',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '进件申请ID',
        'keyword' => '关键字',
        'merchant_id' => '商户',
        'onboarding_config_id' => '进件配置',
        'plugin_code' => '插件编码',
        'subject_type' => '主体类型',
        'apply_products' => '申请产品',
        'form_data' => '进件资料',
        'file_assets' => '文件资料',
        'remark' => '备注',
        'submit' => '是否提交',
        'status' => '状态',
        'approved' => '审核结果',
        'message' => '处理说明',
        'upstream_apply_id' => '上游申请单号',
        'upstream_contract_id' => '上游合同号',
        'upstream_merchant_no' => '上游商户号',
        'upstream_terminal_no' => '上游终端号',
        'upstream_status' => '上游状态',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'onboarding_config_id', 'plugin_code', 'status', 'page', 'page_size'],
        'store' => ['merchant_id', 'onboarding_config_id', 'subject_type', 'apply_products', 'form_data', 'file_assets', 'remark', 'submit'],
        'show' => ['id'],
        'review' => ['id', 'approved', 'message'],
        'manualBind' => ['id', 'upstream_apply_id', 'upstream_contract_id', 'upstream_merchant_no', 'upstream_terminal_no', 'upstream_status', 'message'],
        'action' => ['id'],
    ];

    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'merchant_id' => 'required|integer|min:1',
                'onboarding_config_id' => 'required|integer|min:1',
                'subject_type' => 'required|string|max:32',
                'apply_products' => 'required|array',
            ]),
            'review' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'approved' => 'required|integer|in:0,1',
            ]),
            'show', 'action', 'manualBind' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            default => $rules,
        };
    }
}
