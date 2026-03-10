<?php

namespace app\models;

use app\common\base\BaseModel;

/**
 * 管理员模型
 *
 * 对应表：ma_admin
 */
class Admin extends BaseModel
{
    protected $table = 'ma_admin';

    protected $fillable = [
        'user_name',
        'password',
        'nick_name',
        'avatar',
        'mobile',
        'email',
        'status',
        'login_ip',
        'login_at',
    ];

    public $timestamps = true;

    protected $casts = [
        'status' => 'integer',
        'login_at' => 'datetime',
    ];

    protected $hidden = ['password'];
}
