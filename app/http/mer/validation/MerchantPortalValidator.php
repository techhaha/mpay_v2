<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 商户后台资料与安全页校验器。
 */
class MerchantPortalValidator extends Validator
{
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
    ];

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
    ];

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
    ];
}
