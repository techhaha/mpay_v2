<?php

namespace app\common\enums;

/**
 * 定时任务执行策略（misfire_policy）
 * 对应表：cron_jobs.misfire_policy
 * 1 循环执行  2 执行一次
 */
class CronMisfirePolicy
{
    /**
     * 循环执行
     */
    public const LOOP = 1;

    /**
     * 只执行一次
     */
    public const RUN_ONCE = 2;
}


