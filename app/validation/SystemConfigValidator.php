<?php
declare(strict_types=1);

namespace app\validation;

use support\validation\Validator;

class SystemConfigValidator extends Validator
{
    protected array $rules = [
        'config_key' => 'string|max:100',
        'config_value' => 'nullable|string',
    ];

    protected array $messages = [];

    protected array $attributes = [
        'config_key' => '配置项键名',
        'config_value' => '配置项值',
    ];

    protected array $scenes = [];
}
