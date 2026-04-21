<?php

namespace app\model\system;

use app\common\base\BaseModel;

/**
 * 系统配置模型。
 * 适合全局开关、默认策略和运行时参数。
 */
class SystemConfig extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_system_config';

    /**
     * 主键字段名
     *
     * @var mixed
     */
    protected $primaryKey = 'config_key';

    /**
     * incrementing
     *
     * @var mixed
     */
    public $incrementing = false;

    /**
     * key类型
     *
     * @var mixed
     */
    protected $keyType = 'string';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'config_key',
        'group_code',
        'config_value',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}



