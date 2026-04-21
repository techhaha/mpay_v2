<?php

namespace app\common\base;

use Illuminate\Database\UniqueConstraintViolationException;
use support\Model;
use support\Db;

/**
 * 仓储层基础类。
 *
 * 封装通用 CRUD、条件查询、加锁查询和分页查询能力。
 */
abstract class BaseRepository
{
    /**
     * 当前仓储绑定的模型实例。
     *
     * @var Model
     */
    protected Model $model;

    /**
     * 构造方法。
     *
     * @param Model $model 模型实例
     * @return void
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * 获取查询构造器。
     *
     * @return \Illuminate\Database\Eloquent\Builder 查询构造器
     */
    public function query()
    {
        return $this->model->newQuery();
    }

    /**
     * 按主键查询记录。
     *
     * @param int|string $id 主键
     * @param array $columns 字段列表
     * @return Model|null 记录或空
     */
    public function find(int|string $id, array $columns = ['*']): ?Model
    {
        return $this->query()->find($id, $columns);
    }

    /**
     * 新增记录。
     *
     * @param array $data 新增数据
     * @return Model 新增后的模型
     */
    public function create(array $data): Model
    {
        return $this->query()->create($data);
    }

    /**
     * 按主键更新记录。
     *
     * @param int|string $id 主键
     * @param array $data 更新数据
     * @return bool 是否更新成功
     */
    public function updateById(int|string $id, array $data): bool
    {
        return (bool) $this->query()->whereKey($id)->update($data);
    }

    /**
     * 按唯一键更新记录。
     *
     * @param int|string $key 键值
     * @param array $data 更新数据
     * @return bool 是否更新成功
     */
    public function updateByKey(int|string $key, array $data): bool
    {
        return (bool) $this->query()->whereKey($key)->update($data);
    }

    /**
     * 按条件批量更新记录。
     *
     * @param array $where 条件
     * @param array $data 更新数据
     * @return int 受影响行数
     */
    public function updateWhere(array $where, array $data): int
    {
        $query = $this->query();

        if (!empty($where)) {
            $query->where($where);
        }

        return (int) $query->update($data);
    }

    /**
     * 按主键删除记录。
     *
     * @param int|string $id 主键
     * @return bool 是否删除成功
     */
    public function deleteById(int|string $id): bool
    {
        return (bool) $this->query()->whereKey($id)->delete();
    }

    /**
     * 按条件批量删除记录。
     *
     * @param array $where 条件
     * @return int 受影响行数
     */
    public function deleteWhere(array $where): int
    {
        $query = $this->query();

        if (!empty($where)) {
            $query->where($where);
        }

        return (int) $query->delete();
    }

    /**
     * 按条件获取首条记录。
     *
     * @param array $where 条件
     * @param array $columns 字段列表
     * @return Model|null 记录或空
     */
    public function firstBy(array $where = [], array $columns = ['*']): ?Model
    {
        $query = $this->query();

        if (!empty($where)) {
            $query->where($where);
        }

        return $query->first($columns);
    }

    /**
     * 先查后更，不存在则创建。
     *
     * @param array $where 条件
     * @param array $data 更新数据
     * @return Model 记录
     */
    public function updateOrCreate(array $where, array $data = []): Model
    {
        if ($where === []) {
            return $this->create($data);
        }

        return Db::transaction(function () use ($where, $data): Model {
            $query = $this->query()->lockForUpdate();
            $query->where($where);

            /** @var Model|null $model */
            $model = $query->first();
            if ($model) {
                $model->fill($data);
                $model->save();

                return $model->refresh();
            }

            try {
                return $this->create(array_merge($where, $data));
            } catch (UniqueConstraintViolationException $e) {
                $model = $this->firstBy($where);
                if (!$model) {
                    throw $e;
                }

                $model->fill($data);
                $model->save();

                return $model->refresh();
            }
        });
    }

    /**
     * 按条件统计数量。
     *
     * @param array $where 条件
     * @return int 数量
     */
    public function countBy(array $where = []): int
    {
        $query = $this->query();

        if (!empty($where)) {
            $query->where($where);
        }

        return (int) $query->count();
    }

    /**
     * 判断条件下是否存在记录。
     *
     * @param array $where 条件
     * @return bool 是否存在
     */
    public function existsBy(array $where = []): bool
    {
        $query = $this->query();

        if (!empty($where)) {
            $query->where($where);
        }

        return $query->exists();
    }

    /**
     * 分页查询。
     *
     * @param array $where 条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param array $columns 字段列表
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 分页结果
     */
    public function paginate(array $where = [], int $page = 1, int $pageSize = 10, array $columns = ['*'])
    {
        $query = $this->query();

        if (!empty($where)) {
            $query->where($where);
        }

        return $query->paginate($pageSize, $columns, 'page', $page);
    }
}





