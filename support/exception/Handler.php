<?php

namespace support\exception;

use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;
use support\exception\BusinessException;

/**
 * 自定义异常处理器
 * 基于 webman 的 ExceptionHandler 扩展，统一处理业务异常
 */
class Handler extends ExceptionHandler
{
    /**
     * 不需要记录日志的异常类型
     */
    public $dontReport = [
        BusinessException::class,
    ];

    /**
     * 报告异常（记录日志）
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * 渲染异常响应
     */
    public function render(Request $request, Throwable $exception): Response
    {
        // 业务异常优先走异常自身的 render（参考官方文档：自定义业务异常）
        if ($exception instanceof \Webman\Exception\BusinessException) {
            if (method_exists($exception, 'render')) {
                $response = $exception->render($request);
                if ($response instanceof Response) {
                    return $response;
                }
            }
        }

        // 其他异常使用父类默认处理（debug=true 时带 trace，字段沿用 webman 默认）
        return parent::render($request, $exception);
    }
}

