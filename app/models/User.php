<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 用户模型
 *
 * 对应表：users
 */
class User extends BaseModel
{
    /**
     * 表名
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * 关联角色（多对多）
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }
}


