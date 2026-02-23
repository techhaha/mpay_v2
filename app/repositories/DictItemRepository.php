<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\DictItem;

/**
 * 字典项仓储
 */
class DictItemRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new DictItem());
    }
}


