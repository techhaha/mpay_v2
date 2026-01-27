<?php

namespace app\exceptions;

/**
 * 业务基础异常
 *
 * 说明：
 * - 继承 webman 的 BusinessException，让框架自动捕获并渲染
 * - 重写 render() 以对齐前端期望字段：code/message/data
 */
class BusinessException extends \support\exception\BusinessException
{
    public function __construct(string $message = '', int $bizCode = 500, array $data = [])
    {
        parent::__construct($message, $bizCode);
        $this->data($data);
    }

    public function getBizCode(): int
    {
        return (int)$this->getCode();
    }

    // 保持与 webman BusinessException 方法签名兼容
    public function getData(): array
    {
        return parent::getData();
    }

    /**
     * 自定义渲染
     * - json 请求：返回 {code,message,data}
     * - 非 json：返回文本
     */
    public function render(\Webman\Http\Request $request): ?\Webman\Http\Response
    {
        if ($request->expectsJson()) {
            return json([
                'code' => $this->getBizCode() ?: 500,
                'message' => $this->getMessage(),
                'data' => $this->getData(),
            ]);
        }
        return new \Webman\Http\Response(200, [], $this->getMessage());
    }
}


