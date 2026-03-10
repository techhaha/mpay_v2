<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\AdminService;
use support\Request;

/**
 * 管理员控制器
 */
class AdminController extends BaseController
{
    public function __construct(
        protected AdminService $adminService
    ) {
    }

    /**
     * GET /admin/getUserInfo
     *
     * 获取当前登录管理员信息
     */
    public function getUserInfo(Request $request)
    {
        $adminId = $this->currentUserId($request);
        if ($adminId <= 0) {
            return $this->fail('未获取到用户信息，请先登录', 401);
        }

        $data = $this->adminService->getInfoById($adminId);
        return $this->success($data);
    }
}
