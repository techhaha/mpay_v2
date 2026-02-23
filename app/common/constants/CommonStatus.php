<?php

namespace app\common\constants;

/**
 * 通用启用/禁用状态
 * 多个表 status 字段复用：users.status, departments.status, roles.status, cron_jobs.status 等
 */
class CommonStatus
{
    /**
     * 禁用 / 停用
     */
    public const DISABLED = 0;

    /**
     * 启用
     */
    public const ENABLED = 1;
}


