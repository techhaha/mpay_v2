<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\http\admin\validation\AuthValidator;
use app\service\system\access\AdminAuthService;
use app\service\system\user\AdminUserService;
use support\Request;
use support\Response;

/**
 * 管理员认证控制器。
 */
class AuthController extends BaseController
{
    public function __construct(
        protected AdminAuthService $adminAuthService,
        protected AdminUserService $adminUserService
    ) {
    }

    public function login(Request $request): Response
    {
        $data = $this->validated($request->all(), AuthValidator::class, 'login');

        return $this->success($this->adminAuthService->authenticateCredentials(
            (string) $data['username'],
            (string) $data['password'],
            $request->getRealIp(),
            $request->header('user-agent', '')
        ));
    }

    public function logout(Request $request): Response
    {
        $token = trim((string) ($request->header('authorization', '') ?: $request->header('x-admin-token', '')));
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?: $token;

        if ($token === '') {
            return $this->fail('未获取到登录令牌', 401);
        }

        $this->adminAuthService->revokeToken($token);

        return $this->success(true);
    }

    /**
     * 获取当前登录管理员的信息
     */
    public function profile(Request $request): Response
    {
        $adminId = $this->currentAdminId($request);
        if ($adminId <= 0) {
            return $this->fail('未获取到当前管理员信息', 401);
        }

        return $this->success($this->adminUserService->profile(
            $adminId,
            (string) $this->requestAttribute($request, 'auth.admin_username', '')
        ));
    }
}

