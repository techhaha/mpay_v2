<?php

namespace app\common\enums;

/**
 * 用户性别枚举
 * 对应表：users.sex 以及 gender 字典
 */
class UserSex
{
    /**
     * 女
     */
    public const FEMALE = 0;

    /**
     * 男
     */
    public const MALE = 1;

    /**
     * 未知/其它
     */
    public const UNKNOWN = 2;
}


