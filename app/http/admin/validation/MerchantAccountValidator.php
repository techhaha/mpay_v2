<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 商户账户参数校验器。
 */
class MerchantAccountValidator extends Validator
{
    protected array $rules = [
        'id' => 'required|integer|min:1',
        'keyword' => 'sometimes|string|max:128',
        'merchant_id' => 'sometimes|integer|min:1',
        'page' => 'sometimes|integer|min:1',
        'page_size' => 'sometimes|integer|min:1|max:100',
    ];

    protected array $attributes = [
        'id' => '账户ID',
        'keyword' => '关键词',
        'merchant_id' => '所属商户',
        'page' => '页码',
        'page_size' => '每页条数',
    ];

    protected array $scenes = [
        'index' => ['keyword', 'merchant_id', 'page', 'page_size'],
        'show' => ['id'],
    ];
}
