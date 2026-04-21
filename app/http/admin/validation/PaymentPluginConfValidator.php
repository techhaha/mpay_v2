<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付插件配置参数校验器。
 *
 * 用于校验插件配置列表、详情和增删改参数。
 */
class PaymentPluginConfValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'plugin_code' => 'sometimes|string|alpha_dash|min:2|max:32',
        'config' => 'nullable|array',
        'settlement_cycle_type' => 'sometimes|integer|in:0,1,2,3,4',
        'settlement_cutoff_time' => 'nullable|date_format:H:i:s',
        'remark' => 'nullable|string|max:500',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
        'ids' => 'sometimes|array',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '配置ID',
        'keyword' => '关键字',
        'plugin_code' => '插件编码',
        'config' => '插件配置',
        'settlement_cycle_type' => '结算周期',
        'settlement_cutoff_time' => '结算截止时间',
        'remark' => '备注',
        'page' => '页码',
        'page_size' => '每页条数',
        'ids' => '配置ID集合',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'plugin_code', 'page', 'page_size'],
        'store' => ['plugin_code', 'config', 'settlement_cycle_type', 'settlement_cutoff_time', 'remark'],
        'update' => ['id', 'plugin_code', 'config', 'settlement_cycle_type', 'settlement_cutoff_time', 'remark'],
        'show' => ['id'],
        'destroy' => ['id'],
        'options' => ['plugin_code'],
        'selectOptions' => ['keyword', 'plugin_code', 'page', 'page_size', 'ids'],
    ];

    /**
     * 根据场景返回支付插件配置校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
            ]),
            'show', 'destroy' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            default => $rules,
        };
    }
}


