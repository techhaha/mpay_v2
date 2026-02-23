<?php

namespace app\common\base;

use support\Db;

/**
 * 业务服务层基础父类
 */
class BaseService
{
    /**
     * 事务封装
     *
     * 使用方式：
     * $this->transaction(function () { ... });
     */
    protected function transaction(callable $callback)
    {
        return Db::connection()->transaction(function () use ($callback) {
            return $callback();
        });
    }
}
