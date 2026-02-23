<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\CronJob;

/**
 * 定时任务仓储
 */
class CronJobRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new CronJob());
    }
}


