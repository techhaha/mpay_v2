<?php

namespace app\common\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 全局跨域中间件。
 *
 * 统一处理预检请求和跨域响应头。
 */
class Cors implements MiddlewareInterface
{
    /**
     * 处理请求。
     *
     * @param Request $request 请求对象
     * @param callable $handler handler
     * @return Response 响应对象
     */
    public function process(Request $request, callable $handler): Response
    {
        $response = strtoupper($request->method()) === 'OPTIONS' ? response('', 204) : $handler($request);

        return $response->withHeaders([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin' => $request->header('origin', '*'),
            'Access-Control-Allow-Methods' => $request->header('access-control-request-method', '*'),
            'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', '*'),
        ]);
    }
}




