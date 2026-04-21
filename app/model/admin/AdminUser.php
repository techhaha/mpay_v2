<?php

namespace app\model\admin;

use app\common\base\BaseModel;

/**
 * 管理员账号模型。
 * 表示后台管理员基础资料，不承载登录 token。
 */
class AdminUser extends BaseModel
{
    /**
     * 数据表名
     *
     * @var mixed
     */
    protected $table = 'ma_admin_user';

    /**
     * 可批量赋值字段
     *
     * @var mixed
     */
    protected $fillable = [
        'username',
        'password_hash',
        'real_name',
        'mobile',
        'email',
        'is_super',
        'status',
        'last_login_at',
        'last_login_ip',
        'remark',
    ];

    /**
     * 隐藏字段
     *
     * @var mixed
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * 字段类型转换配置
     *
     * @var mixed
     */
    protected $casts = [
        'is_super' => 'integer',
        'status' => 'integer',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}




