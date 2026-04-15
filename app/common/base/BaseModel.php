<?php

namespace app\common\base;

use app\common\util\FormatHelper;
use DateTimeInterface;
use support\Model;

/**
 * 所有业务模型的基础父类。
 *
 * 统一主键、时间戳和默认批量赋值策略。
 */
class BaseModel extends Model
{
    /**
     * 默认主键字段名。
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 是否自动维护 created_at / updated_at。
     *
     * 大部分业务表都包含这两个字段，如有例外可在子类中覆盖为 false。
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 默认仅保护主键，其他字段按子类 fillable 约束。
     *
     * 建议在具体模型中显式声明 $fillable。
     *
     * @var array
     */
    protected $guarded = ['id'];

    /**
     * 统一模型时间字段的 JSON 输出格式。
     *
     * 避免前端收到 ISO8601（如 2026-04-02T01:50:40.000000Z）这类不直观的时间串，
     * 统一改为后台常用的本地展示格式。
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return FormatHelper::dateTime($date);
    }
}
