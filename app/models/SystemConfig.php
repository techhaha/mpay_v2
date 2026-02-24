<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 系统配置模型
 *
 * 对应表：ma_system_config
 */
class SystemConfig extends BaseModel
{
    /**
     * 启用自动维护 created_at / updated_at
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 表名
     *
     * @var string
     */
    protected $table = 'ma_system_config';

    /**
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = [
        'config_key',
        'config_value',
    ];
}

