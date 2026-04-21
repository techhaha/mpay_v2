<?php

namespace app\http\mer\controller\system;

use app\common\base\BaseController;
use app\http\mer\validation\AuthValidator;
use app\service\merchant\auth\MerchantAuthService;
use support\Request;
use support\Response;

/**
 * 商户认证控制器。
 *
 * @property MerchantAuthService $merchantAuthService 商户认证服务
 */
class AuthController extends BaseController
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
     * 商户登录。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function login(Request $request): Response
    {
        $data = $this->validated($request->all(), AuthValidator::class, 'login');

        return $this->success($this->merchantAuthService->authenticateCredentials(
            (string) $data['merchant_no'],
            (string) $data['password'],
            $request->getRealIp(),
            $request->header('user-agent', '')
        ));
    }

    /**
     * 商户退出登录。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function logout(Request $request): Response
    {
        $token = trim((string) ($request->header('authorization', '') ?: $request->header('x-merchant-token', '')));
        $token = preg_replace('/^Bearer\s+/i', '', $token) ?: $token;

        if ($token === '') {
            return $this->fail('未获取到登录令牌', 401);
        }

        $this->merchantAuthService->revokeToken($token);

        return $this->success(true);
    }

    /**
     * 获取当前登录商户信息。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function profile(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $merchantNo = $this->currentMerchantNo($request);
        return $this->success($this->merchantAuthService->profile($merchantId, $merchantNo));
    }
}






