<?php

namespace app\common\constants;

/**
 * 权限标识常量
 * 对应表：menus.permission 以及前端 permissionData.meta.permission
 */
class Permission
{
    // 系统按钮权限（示例）
    public const SYS_BTN_ADD    = 'sys:btn:add';
    public const SYS_BTN_EDIT   = 'sys:btn:edit';
    public const SYS_BTN_DELETE = 'sys:btn:delete';

    // 通用按钮权限（示例）
    public const COMMON_BTN_ADD    = 'common:btn:add';
    public const COMMON_BTN_EDIT   = 'common:btn:edit';
    public const COMMON_BTN_DELETE = 'common:btn:delete';
}


