<?php

namespace app\events;

use app\repositories\SystemConfigRepository;

/**
 * 系统配置相关事件处理
 *
 * 负责在配置更新后重新从数据库加载缓存
 */
class SystemConfig
{
    /**
     * 重新加载系统配置缓存
     *
     * @param mixed $data 事件数据（此处用不到）
     * @return void
     */
    public function reload($data = null): void
    {
        // 通过仓储重新加载缓存
        $repository = new SystemConfigRepository();
        $repository->reloadCache();
    }
}


