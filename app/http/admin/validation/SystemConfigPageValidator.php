<?php

namespace app\http\admin\validation;

use support\validation\Validator;

class SystemConfigPageValidator extends Validator
{
    protected array $rules = [
        'group_code' => 'required|string|min:1|max:50|regex:/^[a-z0-9_]+$/',
        'values' => 'required|array',
    ];

    protected array $attributes = [
        'group_code' => '配置分组',
        'values' => '配置值',
    ];

    protected array $scenes = [
        'show' => ['group_code'],
        'store' => ['group_code', 'values'],
    ];
}
