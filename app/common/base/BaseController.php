<?php

namespace app\common\base;

use support\Response;
use support\Request;

/**
 * 控制器基础类
 * - 提供统一的 success/fail 响应封装
 *
 * 约定：
 * - 控制器统一通过 $this->request->* 获取请求数据
 * - 为避免每个控制器构造函数重复注入 Request，本类通过 __get('request') 返回当前请求对象
 */
abstract class BaseController
{

    /**
     * 成功响应
     */
    protected function success(mixed $data = null, string $message = 'success', int $code = 200): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应
     */
    protected function fail(string $message = 'fail', int $code = 500, mixed $data = null): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
