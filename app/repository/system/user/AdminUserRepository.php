<?php

namespace app\repository\system\user;

use app\common\base\BaseRepository;
use app\model\admin\AdminUser;

/**
 * 管理员账号仓库。
 */
class AdminUserRepository extends BaseRepository
{
    /**
     * 构造函数，注入对应模型。
     */
    public function __construct()
    {
        parent::__construct(new AdminUser());
    }

    /**
     * 根据用户名查询管理员。
     */
    public function findByUsername(string $username, array $columns = ['*']): ?AdminUser
    {
        return $this->model->newQuery()
            ->where('username', $username)
            ->first($columns);
    }
}


