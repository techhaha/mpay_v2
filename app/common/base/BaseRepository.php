<?php

namespace app\common\base;

use support\Model;

/**
 * 仓储层基础父类
 *
 * 封装单表常用的 CRUD / 分页操作，具体仓储继承后可扩展业务查询。
 */
abstract class BaseRepository
{
    /**
     * @var Model
     */
    protected Model $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * 根据主键查询
     */
    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->model->newQuery()->find($id, $columns);
    }

    /**
     * 新建记录
     */
    public function create(array $data): Model
    {
        return $this->model->newQuery()->create($data);
    }

    /**
     * 按主键更新
     */
    public function updateById(int $id, array $data): bool
    {
        return (bool) $this->model->newQuery()->whereKey($id)->update($data);
    }

    /**
     * 按主键删除
     */
    public function deleteById(int $id): bool
    {
        return (bool) $this->model->newQuery()->whereKey($id)->delete();
    }

    /**
     * 简单分页查询示例
     *
     * @param array $where  ['字段' => 值]，值为 null / '' 时会被忽略
     */
    public function paginate(array $where = [], int $page = 1, int $pageSize = 10, array $columns = ['*'])
    {
        $query = $this->model->newQuery();

        if (!empty($where)) {
            $query->where($where);
        }

        return $query->paginate($pageSize, $columns, 'page', $page);
    }
}
