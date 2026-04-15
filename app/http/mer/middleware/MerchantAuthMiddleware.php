<?php

namespace app\http\mer\middleware;

use app\service\merchant\auth\MerchantAuthService;
use support\Context;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 商户认证中间件。
 *
 * 负责读取商户 token，并把商户身份写入请求上下文。
 */
class MerchantAuthMiddleware implements MiddlewareInterface
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected MerchantAuthService $merchantAuthService
    ) {
    }

    /**
     * 处理请求。
     */
    public function process(Request $request, callable $handler): Response
    {
        $token = trim((string) ($request->header('authorization', '') ?: $request->header('x-merchant-token', '')));
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?: $token;

        if ($token === '') {
            if ((int) env('AUTH_MIDDLEWARE_STRICT', 1) === 1) {
                return json([
                    'code' => 401,
                    'msg' => 'merchant unauthorized',
                    'data' => null,
                ]);
            }
        } else {
            $result = $this->merchantAuthService->authenticateToken(
                $token,
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            if (!$result) {
                return json([
                    'code' => 401,
                    'msg' => 'merchant unauthorized',
                    'data' => null,
                ]);
            }

            Context::set('auth.merchant_id', (int) $result['merchant']->id);
            Context::set('auth.merchant_no', (string) $result['merchant']->merchant_no);
        }

        return $handler($request);
    }
}

