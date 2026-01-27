<?php

namespace app\common\base;

/**
 * 仓库基础类
 * - 支持注入 DAO 依赖
 * - 通过魔术方法代理 DAO 的方法调用
 * - 提供通用的数据访问封装
 */
abstract class BaseRepository
{
    /**
     * DAO 实例（可选，子类通过构造函数注入）
     */
    protected ?BaseDao $dao = null;

    /**
     * 构造函数，子类可注入 DAO
     */
    public function __construct(?BaseDao $dao = null)
    {
        $this->dao = $dao;
    }

    /**
     * 魔术方法：代理 DAO 的方法调用
     * 如果仓库自身没有该方法，且存在 DAO 实例，则调用 DAO 的对应方法
     */
    public function __call(string $method, array $arguments)
    {
        if ($this->dao && method_exists($this->dao, $method)) {
            return $this->dao->{$method}(...$arguments);
        }

        throw new \BadMethodCallException(
            sprintf('Method %s::%s does not exist', static::class, $method)
        );
    }

    /**
     * 检查 DAO 是否已注入
     */
    protected function hasDao(): bool
    {
        return $this->dao !== null;
    }

    /**
     * 获取 DAO 实例
     */
    protected function getDao(): ?BaseDao
    {
        return $this->dao;
    }
}


