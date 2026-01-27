<?php

namespace app\common\base;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use support\Db;

/**
 * DAO 基础类
 * - 封装数据库连接和基础 CRUD 操作
 * - 提供查询构造器访问
 */
abstract class BaseDao
{
    /**
     * 数据库连接名称（子类可覆盖）
     */
    protected string $connection = 'default';

    /**
     * 表名（子类必须定义）
     */
    protected string $table = '';

    /**
     * 获取数据库连接
     */
    protected function connection()
    {
        return Db::connection($this->connection);
    }

    /**
     * 获取查询构造器
     */
    protected function query(): Builder
    {
        return Db::connection($this->connection)->table($this->table);
    }

    /**
     * 根据 ID 查找单条记录
     */
    public function findById(int $id, array $columns = ['*']): ?array
    {
        $result = $this->query()->where('id', $id)->first($columns);
        return $result ? (array)$result : null;
    }

    /**
     * 根据条件查找单条记录
     */
    public function findOne(array $where, array $columns = ['*']): ?array
    {
        $query = $this->query();
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        $result = $query->first($columns);
        return $result ? (array)$result : null;
    }

    /**
     * 根据条件查找多条记录
     */
    public function findMany(array $where = [], array $columns = ['*'], array $orderBy = [], int $limit = 0): array
    {
        $query = $this->query();
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
        $results = $query->get($columns);
        return array_map(fn($item) => (array)$item, $results->toArray());
    }

    /**
     * 插入单条记录
     */
    public function insert(array $data): int
    {
        return $this->query()->insertGetId($data);
    }

    /**
     * 批量插入
     */
    public function insertBatch(array $data): bool
    {
        return $this->query()->insert($data);
    }

    /**
     * 根据 ID 更新记录
     */
    public function updateById(int $id, array $data): int
    {
        return $this->query()->where('id', $id)->update($data);
    }

    /**
     * 根据条件更新记录
     */
    public function update(array $where, array $data): int
    {
        $query = $this->query();
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        return $query->update($data);
    }

    /**
     * 根据 ID 删除记录
     */
    public function deleteById(int $id): int
    {
        return $this->query()->where('id', $id)->delete();
    }

    /**
     * 根据条件删除记录
     */
    public function delete(array $where): int
    {
        $query = $this->query();
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        return $query->delete();
    }

    /**
     * 统计记录数
     */
    public function count(array $where = []): int
    {
        $query = $this->query();
        foreach ($where as $key => $value) {
            $query->where($key, $value);
        }
        return $query->count();
    }

    /**
     * 判断记录是否存在
     */
    public function exists(array $where): bool
    {
        return $this->count($where) > 0;
    }
}


