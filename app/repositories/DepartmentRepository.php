<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\Department;

/**
 * 部门仓储
 */
class DepartmentRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Department());
    }
}


