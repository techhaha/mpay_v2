<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\UserService;
use support\Request;

/**
 * 用户接口示例控制器
 *
 * 主要用于演示 BaseController / Service / Repository / Model 的调用链路。
 */
class UserController extends BaseController
{
    public function __construct(
        protected UserService $userService
    ) {
    }

    /**
     * GET /user/getUserInfo
     *
     * 从 JWT token 中获取当前登录用户信息
     * 前端通过 Authorization: Bearer {token} 请求头传递 token
     */
    public function getUserInfo(Request $request)
    {
        // 从JWT中间件注入的用户信息中获取用户ID
        $userId = $this->currentUserId($request);

        if ($userId <= 0) {
            return $this->fail('未获取到用户信息，请先登录', 401);
        }

        $data = $this->userService->getUserInfoById($userId);
        return $this->success($data);
    }
}

