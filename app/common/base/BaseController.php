<?php

namespace app\common\base;

use support\Request;
use support\Response;

/**
 * 控制器基础父类
 *
 * 约定统一的 JSON 返回结构：
 * {
 *   "code": 200,
 *   "message": "success",
 *   "data": ...
 * }
 */
class BaseController
{
    /**
     * 成功返回
     */
    protected function success(mixed $data = null, string $message = 'success', int $code = 200): Response
    {
        return json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * 失败返回
     */
    protected function fail(string $message = 'error', int $code = 500, mixed $data = null): Response
    {
        return json([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * 获取当前登录用户的 token 载荷
     *
     * 从 AuthMiddleware 注入的用户信息中获取
     */
    protected function currentUser(Request $request): ?array
    {
        return $request->user ?? null;
    }

    /**
     * 获取当前登录用户ID
     */
    protected function currentUserId(Request $request): int
    {
        return (int) ($request->userId ?? 0);
    }
}


