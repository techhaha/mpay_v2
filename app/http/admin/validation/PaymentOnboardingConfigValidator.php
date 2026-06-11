<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 插件进件配置参数校验器。
 */
class PaymentOnboardingConfigValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'plugin_code' => 'sometimes|string|alpha_dash|min:2|max:32',
        'name' => 'sometimes|string|min:2|max:100',
        'config' => 'nullable|array',
        'subject_types' => 'sometimes|array',
        'apply_products' => 'sometimes|array',
        'rate_config' => 'nullable|array',
        'merchant_visible' => 'sometimes|integer|in:0,1',
        'status' => 'sometimes|integer|in:0,1',
        'sort_no' => 'nullable|integer|min:0',
        'description' => 'nullable|string|max:5000',
        'remark' => 'nullable|string|max:500',
        'card_no' => 'sometimes|string|min:8|max:32',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '配置ID',
        'keyword' => '关键字',
        'plugin_code' => '插件编码',
        'name' => '进件配置名称',
        'config' => '接口配置',
        'subject_types' => '主体类型',
        'apply_products' => '申请产品',
        'rate_config' => '费率配置',
        'merchant_visible' => '商户端可见',
        'status' => '状态',
        'sort_no' => '排序',
        'description' => '说明',
        'remark' => '备注',
        'card_no' => '银行卡号',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'plugin_code', 'merchant_visible', 'status', 'page', 'page_size'],
        'store' => ['plugin_code', 'name', 'config', 'subject_types', 'apply_products', 'rate_config', 'merchant_visible', 'status', 'sort_no', 'description', 'remark'],
        'update' => ['id', 'plugin_code', 'name', 'config', 'subject_types', 'apply_products', 'rate_config', 'merchant_visible', 'status', 'sort_no', 'description', 'remark'],
        'show' => ['id'],
        'destroy' => ['id'],
        'cardBin' => ['id', 'card_no'],
    ];

    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
                'name' => 'required|string|min:2|max:100',
                'subject_types' => 'required|array',
                'apply_products' => 'required|array',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
                'name' => 'required|string|min:2|max:100',
                'subject_types' => 'required|array',
                'apply_products' => 'required|array',
            ]),
            'show', 'destroy' => array_merge($rules, [
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
