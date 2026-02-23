<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\Role;

/**
 * 角色仓储
 */
class RoleRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Role());
    }
}


