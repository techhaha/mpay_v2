<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\User;

/**
 * 用户仓储
 */
class UserRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new User());
    }

    /**
     * 根据用户名查询用户
     */
    public function findByUserName(string $userName): ?User
    {
        /** @var User|null $user */
        $user = $this->model
            ->newQuery()
            ->where('user_name', $userName)
            ->first();

        return $user;
    }

    /**
     * 根据主键查询并预加载角色
     */
    public function findWithRoles(int $id): ?User
    {
        /** @var User|null $user */
        $user = $this->model
            ->newQuery()
            ->with('roles')
            ->find($id);

        return $user;
    }
}


