<?php

namespace app\common\base;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * 模型基础类
 * - 统一禁用时间戳（如需要可在子类开启）
 * - 提供常用查询作用域和便捷方法
 */
abstract class BaseModel extends Model
{
    /**
     * 禁用时间戳（默认）
     */
    public $timestamps = false;

    /**
     * 允许批量赋值的字段（子类可覆盖）
     */
    protected $guarded = [];

    /**
     * 连接名称（默认使用配置的 default）
     */
    protected $connection = 'default';

    /**
     * 根据 ID 查找（返回数组格式）
     */
    public static function findById(int $id): ?array
    {
        $model = static::find($id);
        return $model ? $model->toArray() : null;
    }

    /**
     * 根据条件查找单条（返回数组格式）
     */
    public static function findOne(array $where): ?array
    {
        $query = static::query();
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        $model = $query->first();
        return $model ? $model->toArray() : null;
    }

    /**
     * 根据条件查找多条（返回数组格式）
     */
    public static function findMany(array $where = [], array $orderBy = [], int $limit = 0): array
    {
        $query = static::query();
        foreach ($where as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else {
                $query->where($key, $value);
            }
        }
        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }
        return $query->get()->map(fn($item) => $item->toArray())->toArray();
    }

    /**
     * 启用状态作用域
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    /**
     * 禁用状态作用域
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('status', 0);
    }

    /**
     * 转换为数组（统一处理 null 值）
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        // 将 null 值转换为空字符串（可选，根据业务需求调整）
        return array_map(fn($value) => $value === null ? '' : $value, $array);
    }
}


