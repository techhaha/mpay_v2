<?php

namespace app\http\admin\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\common\utils\JwtUtil;

/**
 * JWT 认证中间件
 *
 * 验证请求中的 JWT token，并将用户信息注入到请求对象中
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * 处理请求
     * @param Request $request 请求对象
     * @param callable $handler 下一个中间件处理函数
     * @return Response 响应对象
     */
    public function process(Request $request, callable $handler): Response
    {
        // 从请求头中获取 token
        $auth = $request->header('Authorization', '');
        if (!$auth) {
            return $this->unauthorized('缺少认证令牌');
        }

        // 兼容 "Bearer xxx" 或直接 "xxx"
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        } else {
            $token = $auth;
        }

        if (!$token) {
            return $this->unauthorized('认证令牌格式错误');
        }

        try {
            // 解析 JWT token
            $payload = JwtUtil::parseToken($token);
            
            if (empty($payload) || !isset($payload['user_id'])) {
                return $this->unauthorized('认证令牌无效');
            }

            // 将用户信息存储到请求对象中，供控制器使用
            $request->user = $payload;
            $request->userId = (int) ($payload['user_id'] ?? 0);

            // 继续处理请求
            return $handler($request);
        } catch (\Throwable $e) {
            // 根据异常类型返回不同的错误信息
            $message = $e->getMessage();
            if (str_contains($message, 'expired') || str_contains($message, 'Expired')) {
                return $this->unauthorized('认证令牌已过期', 401);
            } elseif (str_contains($message, 'signature') || str_contains($message, 'Signature')) {
                return $this->unauthorized('认证令牌签名无效', 401);
            } else {
                return $this->unauthorized('认证令牌验证失败：' . $message, 401);
            }
        }
    }

    /**
     * 返回未授权响应
     */
    private function unauthorized(string $message, int $code = 401): Response
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $code);
    }
}

