<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\MenuService;
use support\Request;

/**
 * 菜单控制器
 */
class MenuController extends BaseController
{
    public function __construct(
        protected MenuService $menuService
    ) {
    }

    public function getRouters()
    {
        $routers = $this->menuService->getRouters();
        return $this->success($routers);
    }
}

