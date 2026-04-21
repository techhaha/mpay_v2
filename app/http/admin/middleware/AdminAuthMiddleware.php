<?php

namespace app\http\admin\middleware;

use app\service\system\access\AdminAuthService;
use support\Context;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 管理员认证中间件。
 *
 * 负责读取管理员 token，并把管理员身份写入请求上下文。
 *
 * @property AdminAuthService $adminAuthService 管理认证服务
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    /**
     * 构造方法。
     *
     * @param AdminAuthService $adminAuthService 管理认证服务
     * @return void
     */
    public function __construct(
        protected AdminAuthService $adminAuthService
    ) {
    }

    /**
     * 处理请求。
     *
     * @param Request $request 请求对象
     * @param callable $handler handler
     * @return Response 响应对象
     */
    public function process(Request $request, callable $handler): Response
    {
        $token = trim((string) ($request->header('authorization', '') ?: $request->header('x-admin-token', '')));
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?: $token;

        if ($token === '') {
            if ((int) env('AUTH_MIDDLEWARE_STRICT', 1) === 1) {
                return json([
                    'code' => 401,
                    'msg' => 'admin unauthorized',
                    'data' => null,
                ]);
            }
        } else {
            $admin = $this->adminAuthService->authenticateToken(
                $token,
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            if (!$admin) {
                return json([
                    'code' => 401,
                    'msg' => 'admin unauthorized',
                    'data' => null,
                ]);
            }

            Context::set('auth.admin_id', (int) $admin->id);
            Context::set('auth.admin_username', (string) $admin->username);
        }

        return $handler($request);
    }
}





