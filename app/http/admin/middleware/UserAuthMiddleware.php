<?php

namespace app\http\admin\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Class UserAuthMiddleware
 * @package app\http\admin\middleware
 */
class UserAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 检查用户是否已经登录，这里假设通过 session 中的 'user_id' 判断
        if (!$request->session()->has('user_id')) {
            // 用户未登录，重定向到登录页面
            return redirect('/login');
        }

        // 用户已登录，继续处理请求
        return $handler($request);
    }
}