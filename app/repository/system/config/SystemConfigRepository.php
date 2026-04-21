<?php

namespace app\repository\system\config;

use app\common\base\BaseRepository;
use app\model\system\SystemConfig;

/**
 * 系统配置仓库。
 */
class SystemConfigRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new SystemConfig());
    }
}





