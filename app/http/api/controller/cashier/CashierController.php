<?php

namespace app\http\api\controller\cashier;

use app\common\base\BaseController;
use app\http\api\validation\CashierValidator;
use app\service\payment\cashier\CashierService;
use support\Request;
use support\Response;

/**
 * 收银台控制器。
 *
 * 提供收银台上下文查询和支付确认入口。
 */
class CashierController extends BaseController
{
    public function __construct(
        protected CashierService $cashierService
    ) {
    }

    /**
     * 查询收银台上下文。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function context(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'context');

        return $this->success(
            $this->cashierService->context((string) ($payload['biz_no'] ?? ''))
        );
    }

    /**
     * 确认收银台支付方式。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function confirm(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'confirm');

        return $this->success(
            $this->cashierService->confirm($payload, $request)
        );
    }

    /**
     * 查询支付页详情。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function payOrder(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'pay_order');

        return $this->success(
            $this->cashierService->payOrderDetail((string) ($payload['pay_no'] ?? ''))
        );
    }

    /**
     * 查询支付单状态。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function payOrderStatus(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'pay_order_status');

        return $this->success(
            $this->cashierService->payOrderStatus((string) ($payload['pay_no'] ?? ''))
        );
    }

    /**
     * 用户身份回填后继续支付。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function identityResume(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'identity_resume');

        return $this->success(
            $this->cashierService->resumeIdentity($payload, $request)
        );
    }

    /**
     * 查询用户身份承接页上下文。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function identityContext(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'identity_context');

        return $this->success(
            $this->cashierService->identityContext((string) ($payload['token'] ?? $payload['resume_token'] ?? ''))
        );
    }

    /**
     * 微信网页授权回调。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function identityWechatCallback(Request $request): Response
    {
        $payload = $this->validated($request->all(), CashierValidator::class, 'identity_wechat_callback');

        return $this->cashierService->wechatIdentityCallback($payload, $request);
    }
}
