<?php

namespace app\repository\system\user;

use app\common\base\BaseRepository;
use app\model\admin\AdminUser;

/**
 * 管理员账号仓库。
 *
 * 封装管理员用户名查询等基础读方法。
 */
class AdminUserRepository extends BaseRepository
{
    /**
     * 构造方法。
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(new AdminUser());
    }

    /**
     * 根据用户名查询管理员。
     *
     * @param string $username 用户名
     * @param array $columns 字段列表
     * @return AdminUser|null 管理员记录
     */
    public function findByUsername(string $username, array $columns = ['*']): ?AdminUser
    {
        return $this->model->newQuery()
            ->where('username', $username)
            ->first($columns);
    }
}






