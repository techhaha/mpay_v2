<?php

namespace app\model\system;

use app\common\base\BaseModel;

/**
 * 系统配置模型。
 * 适合全局开关、默认策略和运行时参数。
 */
class SystemConfig extends BaseModel
{
    protected $table = 'ma_system_config';

    protected $primaryKey = 'config_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'config_key',
        'group_code',
        'config_value',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

