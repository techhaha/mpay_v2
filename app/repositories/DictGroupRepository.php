<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\DictGroup;

/**
 * 字典分组仓储
 */
class DictGroupRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new DictGroup());
    }
}


