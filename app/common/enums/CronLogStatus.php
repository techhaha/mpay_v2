<?php

namespace app\common\enums;

/**
 * 定时任务日志状态
 * 对应表：cron_logs.status
 * 1 成功  0 失败
 */
class CronLogStatus
{
    public const FAIL    = 0;
    public const SUCCESS = 1;
}


