<?php

namespace app\http\admin\validation;

use support\validation\Validator;

/**
 * 系统配置页面校验器
 */
class SystemConfigPageValidator extends Validator
{
    /**
     * 校验规则
     *
     * @var array
     */
    protected array $rules = [
        'group_code' => 'required|string|min:1|max:50|regex:/^[a-z0-9_]+$/',
        'values' => 'required|array',
    ];

    /**
     * 字段别名
     *
     * @var array
     */
    protected array $attributes = [
        'group_code' => '配置分组',
        'values' => '配置值',
    ];

    /**
     * 校验场景
     *
     * @var array
     */
    protected array $scenes = [
        'show' => ['group_code'],
        'store' => ['group_code', 'values'],
    ];
}


