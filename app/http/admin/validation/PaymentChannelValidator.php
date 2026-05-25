<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 支付通道参数校验器。
 *
 * 用于校验支付通道的查询和增删改参数。
 */
class PaymentChannelValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:0',
        'name' => 'sometimes|string|min:2|max:128',
        'split_rate_bp' => 'sometimes|integer|min:0|max:10000',
        'cost_rate_bp' => 'sometimes|integer|min:0|max:10000',
        'channel_mode' => 'sometimes|integer|in:0,1',
        'pay_type_id' => 'sometimes|integer|min:1|exists:ma_payment_type,id',
        'plugin_code' => 'sometimes|string|min:1|max:64|exists:ma_payment_plugin,code',
        'api_config_id' => 'sometimes|integer|min:1',
        'daily_limit_amount' => 'nullable|integer|min:0',
        'daily_limit_count' => 'nullable|integer|min:0',
        'min_amount' => 'nullable|integer|min:0',
        'max_amount' => 'nullable|integer|min:0',
        'remark' => 'nullable|string|max:255',
        'status' => 'sometimes|integer|in:0,1',
        'sort_no' => 'nullable|integer|min:0',
        'money' => 'sometimes|numeric|min:0.01',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'id' => '通道ID',
        'keyword' => '关键字',
        'merchant_id' => '所属商户',
        'name' => '通道名称',
        'split_rate_bp' => '商户分账比例',
        'cost_rate_bp' => '第三方通道成本',
        'channel_mode' => '通道模式',
        'pay_type_id' => '支付方式',
        'plugin_code' => '支付插件',
        'api_config_id' => '配置ID',
        'daily_limit_amount' => '单日限额',
        'daily_limit_count' => '单日限笔',
        'min_amount' => '最小金额',
        'max_amount' => '最大金额',
        'remark' => '备注',
        'status' => '通道状态',
        'sort_no' => '排序',
        'money' => '测试金额',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'pay_type_id', 'plugin_code', 'channel_mode', 'status', 'page', 'page_size'],
        'store' => ['merchant_id', 'name', 'split_rate_bp', 'cost_rate_bp', 'channel_mode', 'pay_type_id', 'plugin_code', 'api_config_id', 'daily_limit_amount', 'daily_limit_count', 'min_amount', 'max_amount', 'remark', 'status', 'sort_no'],
        'update' => ['id', 'merchant_id', 'name', 'split_rate_bp', 'cost_rate_bp', 'channel_mode', 'pay_type_id', 'plugin_code', 'api_config_id', 'daily_limit_amount', 'daily_limit_count', 'min_amount', 'max_amount', 'remark', 'status', 'sort_no'],
        'updateStatus' => ['id', 'status'],
        'show' => ['id'],
        'destroy' => ['id'],
        'test' => ['id', 'name', 'money'],
    ];

    /**
     * 根据场景返回支付通道校验规则。
     *
     * @return array 校验规则
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'store' => array_merge($rules, [
                'merchant_id' => 'required|integer|min:0',
                'name' => 'required|string|min:2|max:128',
                'split_rate_bp' => 'required|integer|min:0|max:10000',
                'cost_rate_bp' => 'required|integer|min:0|max:10000',
                'channel_mode' => 'required|integer|in:0,1',
                'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
                'plugin_code' => 'required|string|min:1|max:64|exists:ma_payment_plugin,code',
                'api_config_id' => 'required|integer|min:1',
                'status' => 'required|integer|in:0,1',
            ]),
            'update' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'merchant_id' => 'required|integer|min:0',
                'name' => 'required|string|min:2|max:128',
                'split_rate_bp' => 'required|integer|min:0|max:10000',
                'cost_rate_bp' => 'required|integer|min:0|max:10000',
                'channel_mode' => 'required|integer|in:0,1',
                'pay_type_id' => 'required|integer|min:1|exists:ma_payment_type,id',
                'plugin_code' => 'required|string|min:1|max:64|exists:ma_payment_plugin,code',
                'api_config_id' => 'required|integer|min:1',
                'status' => 'required|integer|in:0,1',
            ]),
            'updateStatus' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'status' => 'required|integer|in:0,1',
            ]),
            'show', 'destroy' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            'test' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'name' => 'required|string|min:1|max:128',
                'money' => 'required|numeric|min:0.01',
            ]),
            default => $rules,
        };
    }
}



