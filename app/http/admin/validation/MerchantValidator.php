<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户列表参数校验器。
 *
 * 目前只用于列表查询，后续如需新增、编辑、详情校验，可以继续补充场景。
 */
class MerchantValidator extends Validator
{
    protected array $rules = [
        'id' => 'sometimes|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'group_id' => 'sometimes|integer|min:1',
        'status' => 'sometimes|integer|in:0,1',
        'merchant_type' => 'sometimes|integer|in:0,1,2',
        'merchant_no' => 'sometimes|string|max:32',
        'merchant_name' => 'sometimes|string|max:100',
        'merchant_short_name' => 'sometimes|string|max:60',
        'password' => 'sometimes|string|min:6|max:32',
        'password_confirm' => 'sometimes|string|min:6|max:32|same:password',
        'risk_level' => 'sometimes|integer|in:0,1,2',
        'contact_name' => 'sometimes|string|max:50',
        'contact_phone' => 'sometimes|string|max:20',
        'contact_email' => 'sometimes|string|max:100',
        'settlement_account_name' => 'sometimes|string|max:100',
        'settlement_account_no' => 'sometimes|string|max:100',
        'settlement_bank_name' => 'sometimes|string|max:100',
        'settlement_bank_branch' => 'sometimes|string|max:100',
        'remark' => 'sometimes|string|max:500',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '商户ID',
        'keyword' => '关键字',
        'group_id' => '商户分组',
        'status' => '状态',
        'merchant_type' => '商户类型',
        'merchant_no' => '商户号',
        'merchant_name' => '商户名称',
        'merchant_short_name' => '商户简称',
        'password' => '登录密码',
        'password_confirm' => '确认密码',
        'risk_level' => '风控等级',
        'contact_name' => '联系人',
        'contact_phone' => '联系电话',
        'contact_email' => '联系邮箱',
        'settlement_account_name' => '结算账户名',
        'settlement_account_no' => '结算账号',
        'settlement_bank_name' => '开户行',
        'settlement_bank_branch' => '开户支行',
        'remark' => '备注',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'group_id', 'status', 'merchant_type', 'risk_level', 'page', 'page_size'],
        'show' => ['id'],
        'overview' => ['id'],
        'store' => [
            'merchant_name',
            'merchant_short_name',
            'merchant_type',
            'group_id',
            'risk_level',
            'contact_name',
            'contact_phone',
            'contact_email',
            'settlement_account_name',
            'settlement_account_no',
            'settlement_bank_name',
            'settlement_bank_branch',
            'status',
            'remark',
        ],
        'update' => [
            'id',
            'merchant_name',
            'merchant_short_name',
            'merchant_type',
            'group_id',
            'risk_level',
            'contact_name',
            'contact_phone',
            'contact_email',
            'settlement_account_name',
            'settlement_account_no',
            'settlement_bank_name',
            'settlement_bank_branch',
            'status',
            'remark',
        ],
        'updateStatus' => ['id', 'status'],
        'resetPassword' => ['id', 'password', 'password_confirm'],
        'destroy' => ['id'],
    ];

    public function sceneStore(): static
    {
        return $this->appendRules([
            'merchant_name' => 'required|string|max:100',
            'merchant_type' => 'required|integer|in:0,1,2',
            'group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
            'risk_level' => 'required|integer|in:0,1,2',
            'contact_name' => 'required|string|max:50',
            'contact_phone' => 'required|string|max:20',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    public function sceneUpdate(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
            'merchant_name' => 'required|string|max:100',
            'merchant_type' => 'required|integer|in:0,1,2',
            'group_id' => 'required|integer|min:1|exists:ma_merchant_group,id',
            'risk_level' => 'required|integer|in:0,1,2',
            'contact_name' => 'required|string|max:50',
            'contact_phone' => 'required|string|max:20',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    public function sceneUpdateStatus(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
            'status' => 'required|integer|in:0,1',
        ]);
    }

    public function sceneResetPassword(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
            'password' => 'required|string|min:6|max:32',
            'password_confirm' => 'required|string|min:6|max:32|same:password',
        ]);
    }

    public function sceneDestroy(): static
    {
        return $this->appendRules([
            'id' => 'required|integer|min:1',
        ]);
    }
}
