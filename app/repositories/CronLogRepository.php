<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\CronLog;

/**
 * 定时任务日志仓储
 */
class CronLogRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new CronLog());
    }
}


