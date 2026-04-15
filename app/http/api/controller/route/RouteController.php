<?php

namespace app\http\api\controller\route;

use app\common\base\BaseController;
use app\http\api\validation\RouteResolveValidator;
use app\service\payment\runtime\PaymentRouteService;
use support\Request;
use support\Response;

/**
 * 路由预览控制器。
 *
 * 用于返回指定商户分组、支付方式和金额条件下的路由解析结果。
 */
class RouteController extends BaseController
{
    /**
     * 构造函数，注入路由服务。
     */
    public function __construct(
        protected PaymentRouteService $paymentRouteService
    ) {
    }

    /**
     * GET /api/routes/resolve
     *
     * 解析支付路由。
     */
    public function resolve(Request $request): Response
    {
        $data = $this->validated($request->all(), RouteResolveValidator::class, 'resolve');

        return $this->success($this->paymentRouteService->resolveByMerchantGroup(
            (int) $data['merchant_group_id'],
            (int) $data['pay_type_id'],
            (int) $data['pay_amount'],
            $data
        ));
    }
}

