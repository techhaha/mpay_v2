<?php

namespace app\http\mer\middleware;

use app\exception\UnauthorizedException;
use app\service\merchant\auth\MerchantAuthService;
use support\Context;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 商户认证中间件。
 *
 * 负责读取商户 token，并把商户身份写入请求上下文。
 *
 * @property MerchantAuthService $merchantAuthService 商户认证服务
 */
class MerchantAuthMiddleware implements MiddlewareInterface
{
    /**
     * 构造方法。
     *
     * @param MerchantAuthService $merchantAuthService 商户认证服务
     * @return void
     */
    public function __construct(
        protected MerchantAuthService $merchantAuthService
    ) {
    }

    /**
     * 处理请求。
     *
     * @param Request $request 请求对象
     * @param callable $handler handler
     * @return Response 响应对象
     * @throws UnauthorizedException
     */
    public function process(Request $request, callable $handler): Response
    {
        $token = trim((string) ($request->header('authorization', '') ?: $request->header('x-merchant-token', '')));
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?: $token;

        if ($token === '') {
            if ((int) env('AUTH_MIDDLEWARE_STRICT', 1) === 1) {
                throw new UnauthorizedException('商户未授权');
            }
        } else {
            $result = $this->merchantAuthService->authenticateToken(
                $token,
                $request->getRealIp(),
                $request->header('user-agent', '')
            );
            if (!$result) {
                throw new UnauthorizedException('商户未授权');
            }

            Context::set('auth.merchant_id', (int) $result['merchant']->id);
            Context::set('auth.merchant_no', (string) $result['merchant']->merchant_no);
        }

        return $handler($request);
    }
}






