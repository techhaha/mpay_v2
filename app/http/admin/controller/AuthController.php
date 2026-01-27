<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use support\Request;
use support\Response;
use app\services\auth\AuthService;

class AuthController extends BaseController
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * 管理后台登录
     */
    public function login(Request $request): Response
    {
        $username = (string)$request->post('username', '');
        $password = (string)$request->post('password', '');
        // 前端有本地验证码，这里暂不做服务端校验，仅预留字段
        $verifyCode = $request->post('verifyCode');

        if ($username === '' || $password === '') {
            return $this->fail('账号或密码不能为空', 400);
        }

        $token = $this->authService->login($username, $password, $verifyCode);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取当前登录用户信息
     */
    public function getUserInfo(Request $request): Response
    {
        // 前端在 Authorization 中直接传 token
        $token = (string)$request->header('authorization', '');
        $id = $request->get('id');

        $data = $this->authService->getUserInfo($token, $id);

        return $this->success($data);
    }
}
