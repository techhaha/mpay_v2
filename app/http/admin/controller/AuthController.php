<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\services\AuthService;
use app\services\CaptchaService;
use support\Request;

/**
 * 认证控制器
 *
 * 处理登录、验证码等认证相关接口
 */
class AuthController extends BaseController
{
    public function __construct(
        protected CaptchaService $captchaService,
        protected AuthService $authService
    ) {
    }

    /**
     * GET /captcha
     *
     * 生成验证码
     */
    public function captcha(Request $request)
    {
        try {
            $data = $this->captchaService->generate();
            return $this->success($data);
        } catch (\Throwable $e) {
            return $this->fail('验证码生成失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /login
     *
     * 用户登录
     */
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');
        $verifyCode = $request->post('verifyCode', '');
        $captchaId = $request->post('captchaId', '');

        // 参数校验
        if (empty($username) || empty($password) || empty($verifyCode) || empty($captchaId)) {
            return $this->fail('请填写完整登录信息', 400);
        }

        try {
            $data = $this->authService->login($username, $password, $verifyCode, $captchaId);
            return $this->success($data);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->fail('登录失败：' . $e->getMessage(), 500);
        }
    }
}

