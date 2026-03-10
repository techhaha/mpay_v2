<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\Admin;

/**
 * 管理员仓储
 */
class AdminRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Admin());
    }

    /**
     * 根据用户名查询
     */
    public function findByUserName(string $userName): ?Admin
    {
        /** @var Admin|null $admin */
        $admin = $this->model
            ->newQuery()
            ->where('user_name', $userName)
            ->first();

        return $admin;
    }
}
