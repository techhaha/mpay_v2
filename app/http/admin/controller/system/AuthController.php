<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\http\admin\validation\AuthValidator;
use app\service\system\access\AdminAuthService;
use app\service\system\user\AdminUserService;
use support\limiter\Limiter;
use support\Request;
use support\Response;

/**
 * 管理员认证控制器。
 *
 * @property AdminAuthService $adminAuthService 管理认证服务
 * @property AdminUserService $adminUserService 管理用户服务
 */
class AuthController extends BaseController
{
    /**
 * 构造方法。
     *
     * @param AdminAuthService $adminAuthService 管理认证服务
     * @param AdminUserService $adminUserService 管理用户服务
     * @return void
     */
    public function __construct(
        protected AdminAuthService $adminAuthService,
        protected AdminUserService $adminUserService
    ) {
    }

    /**
     * 管理员登录。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function login(Request $request): Response
    {
        $ip = $request->getRealIp();
        Limiter::check('admin-login-ip:' . $ip, 10, 60, '登录请求过于频繁，请稍后再试');

        $data = $this->validated($request->all(), AuthValidator::class, 'login');
        Limiter::check('admin-login-account:' . md5($ip . ':' . strtolower((string) $data['username'])), 5, 300, '账号登录尝试过于频繁，请稍后再试');

        return $this->success($this->adminAuthService->authenticateCredentials(
            (string) $data['username'],
            (string) $data['password'],
            $request->getRealIp(),
            $request->header('user-agent', '')
        ));
    }

    /**
     * 管理员退出登录。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
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
     * 获取当前登录管理员信息。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
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

    /**
     * 修改当前登录管理员密码。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function changePassword(Request $request): Response
    {
        $adminId = $this->currentAdminId($request);
        if ($adminId <= 0) {
            return $this->fail('未获取到当前管理员信息', 401);
        }

        $data = $this->validated($request->all(), AuthValidator::class, 'changePassword');

        return $this->success($this->adminUserService->changePassword($adminId, $data));
    }
}






