<?php

namespace app\http\mer\validation;

use support\validation\Validator;

/**
 * 商户余额查询参数校验器。
 *
 * 用于校验商户余额查询入参。
 */
class BalanceValidator extends Validator
{
    protected array $rules = [
        'merchant_no' => 'required|string|min:1|max:64',
    ];

    protected array $attributes = [
        'merchant_no' => '商户号',
    ];

    protected array $scenes = [
        'show' => ['merchant_no'],
    ];
}
