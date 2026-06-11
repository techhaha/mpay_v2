<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 商户后台资料与安全页校验器。
 */
class MerchantPortalValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'merchant_short_name' => 'sometimes|string|max:64',
        'contact_name' => 'sometimes|string|max:64',
        'contact_phone' => 'sometimes|string|max:32',
        'contact_email' => 'sometimes|email|max:128',
        'settlement_account_name' => 'sometimes|string|max:128',
        'settlement_account_no' => 'sometimes|string|max:128',
        'settlement_bank_name' => 'sometimes|string|max:128',
        'settlement_bank_branch' => 'sometimes|string|max:128',
        'current_password' => 'sometimes|string|min:6|max:32',
        'password' => 'sometimes|string|min:6|max:32',
        'password_confirm' => 'sometimes|string|min:6|max:32|same:password',
        'pay_type_id' => 'required|integer|min:1',
        'pay_amount' => 'required|integer|min:1',
        'stat_date' => 'sometimes|date',
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'plugin_code' => 'sometimes|string|alpha_dash|min:2|max:32',
        'plugin_type' => 'sometimes|integer|in:1,2,3',
        'config' => 'nullable|array',
        'settlement_cycle_type' => 'sometimes|integer|in:0,1,2,3,4',
        'settlement_cutoff_time' => 'nullable|date_format:H:i:s',
        'name' => 'sometimes|string|min:2|max:100',
        'api_config_id' => 'sometimes|integer|min:1',
        'daily_limit_amount' => 'nullable|integer|min:0',
        'daily_limit_count' => 'nullable|integer|min:0',
        'min_amount' => 'nullable|integer|min:0',
        'max_amount' => 'nullable|integer|min:0',
        'remark' => 'nullable|string|max:500',
        'status' => 'sometimes|integer|in:0,1',
        'rotate_v1' => 'sometimes|integer|in:0,1',
        'rotate_v2' => 'sometimes|integer|in:0,1',
        'sort_no' => 'nullable|integer|min:0',
        'items' => 'sometimes|array',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'merchant_short_name' => '商户简称',
        'contact_name' => '联系人',
        'contact_phone' => '联系电话',
        'contact_email' => '联系邮箱',
        'settlement_account_name' => '结算账户名',
        'settlement_account_no' => '结算账号',
        'settlement_bank_name' => '开户行',
        'settlement_bank_branch' => '开户支行',
        'current_password' => '当前密码',
        'password' => '新密码',
        'password_confirm' => '确认密码',
        'pay_type_id' => '支付方式',
        'pay_amount' => '支付金额',
        'stat_date' => '统计日期',
        'id' => '记录ID',
        'keyword' => '关键字',
        'plugin_code' => '支付插件',
        'plugin_type' => '插件类型',
        'config' => '插件配置',
        'settlement_cycle_type' => '结算周期',
        'settlement_cutoff_time' => '结算截止时间',
        'name' => '通道名称',
        'api_config_id' => '插件配置',
        'daily_limit_amount' => '单日限额',
        'daily_limit_count' => '单日限笔',
        'min_amount' => '单笔最小金额',
        'max_amount' => '单笔最大金额',
        'remark' => '备注',
        'status' => '状态',
        'rotate_v1' => 'V1 凭证',
        'rotate_v2' => 'V2 凭证',
        'sort_no' => '排序',
        'items' => '路由配置',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'profileUpdate' => [
            'merchant_short_name',
            'contact_name',
            'contact_phone',
            'contact_email',
            'settlement_account_name',
            'settlement_account_no',
            'settlement_bank_name',
            'settlement_bank_branch',
        ],
        'passwordUpdate' => ['current_password', 'password', 'password_confirm'],
        'routePreview' => ['pay_type_id', 'pay_amount', 'stat_date'],
        'pluginConfigIndex' => ['keyword', 'plugin_code', 'plugin_type', 'page', 'page_size'],
        'pluginConfigShow' => ['id'],
        'pluginConfigStore' => ['plugin_code', 'config', 'settlement_cycle_type', 'settlement_cutoff_time', 'remark'],
        'pluginConfigUpdate' => ['id', 'plugin_code', 'config', 'settlement_cycle_type', 'settlement_cutoff_time', 'remark'],
        'pluginConfigDestroy' => ['id'],
        'channelStore' => ['name', 'pay_type_id', 'plugin_code', 'api_config_id', 'daily_limit_amount', 'daily_limit_count', 'min_amount', 'max_amount', 'remark', 'status', 'sort_no'],
        'channelUpdate' => ['id', 'name', 'pay_type_id', 'plugin_code', 'api_config_id', 'daily_limit_amount', 'daily_limit_count', 'min_amount', 'max_amount', 'remark', 'status', 'sort_no'],
        'channelDestroy' => ['id'],
        'routeConfigUpdate' => ['items'],
        'issueCredential' => ['rotate_v1', 'rotate_v2', 'status'],
    ];

    public function rules(): array
    {
        $rules = parent::rules();

        return match ($this->scene()) {
            'pluginConfigStore' => array_merge($rules, [
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
            ]),
            'pluginConfigUpdate' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
            ]),
            'pluginConfigShow', 'pluginConfigDestroy', 'channelDestroy' => array_merge($rules, [
                'id' => 'required|integer|min:1',
            ]),
            'channelStore' => array_merge($rules, [
                'name' => 'required|string|min:2|max:100',
                'pay_type_id' => 'required|integer|min:1',
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
                'api_config_id' => 'required|integer|min:1',
                'status' => 'required|integer|in:0,1',
            ]),
            'channelUpdate' => array_merge($rules, [
                'id' => 'required|integer|min:1',
                'name' => 'required|string|min:2|max:100',
                'pay_type_id' => 'required|integer|min:1',
                'plugin_code' => 'required|string|alpha_dash|min:2|max:32',
                'api_config_id' => 'required|integer|min:1',
                'status' => 'required|integer|in:0,1',
            ]),
            default => $rules,
        };
    }
}
