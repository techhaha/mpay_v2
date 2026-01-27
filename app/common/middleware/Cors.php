<?php

namespace app\common\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 全局跨域中间件
 * 处理前后端分离项目中的跨域请求问题
 */
class Cors implements MiddlewareInterface
{
    /**
     * 处理请求
     * @param Request $request 请求对象
     * @param callable $handler 下一个中间件处理函数
     * @return Response 响应对象
     */
    public function process(Request $request, callable $handler): Response
    {
        $response = strtoupper($request->method()) === 'OPTIONS' ? response('', 204) : $handler($request);

        $response->withHeaders([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin' => $request->header('origin', '*'),
            'Access-Control-Allow-Methods' => $request->header('access-control-request-method', '*'),
            'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', '*'),
        ]);

        return $response;
    }
}
