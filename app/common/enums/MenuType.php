<?php

namespace app\common\enums;

/**
 * 菜单类型枚举
 * 对应表：menus.type
 * 1 目录  2 菜单  3 按钮
 */
class MenuType
{
    /**
     * 目录
     */
    public const DIRECTORY = 1;

    /**
     * 菜单
     */
    public const MENU = 2;

    /**
     * 按钮（权限点）
     */
    public const BUTTON = 3;
}


