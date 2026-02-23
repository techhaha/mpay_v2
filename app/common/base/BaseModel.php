<?php

namespace app\common\base;

use support\Model;

/**
 * 所有业务模型的基础父类
 */
class BaseModel extends Model
{
    /**
     * 约定所有主键字段名
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 是否自动维护 created_at / updated_at
     *
     * 大部分业务表都有这两个字段，如不需要可在子类里覆盖为 false。
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 默认不禁止任何字段的批量赋值
     *
     * 建议在具体模型中按需设置 $fillable 或 $guarded。
     *
     * @var array
     */
    protected $guarded = [];
}
