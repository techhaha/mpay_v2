<?php

namespace app\http\api\validation;

use support\validation\Validator;

/**
 * 统一追踪查询参数校验器。
 */
class TraceQueryValidator extends Validator
{
    protected array $rules = [
        'trace_no' => 'required|string|min:1|max:64',
    ];

    protected array $attributes = [
        'trace_no' => '追踪号',
    ];

    protected array $scenes = [
        'show' => ['trace_no'],
    ];
}
