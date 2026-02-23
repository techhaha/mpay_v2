<?php

namespace app\common\enums;

/**
 * 定时任务类型
 * 对应表：cron_jobs.task_type
 * 0 cron 表达式  1 时间间隔（秒）
 */
class CronTaskType
{
    public const CRON_EXPRESSION = 0;
    public const INTERVAL_SECOND = 1;
}


