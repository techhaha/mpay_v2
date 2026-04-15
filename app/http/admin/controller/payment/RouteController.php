<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\http\admin\validation\RouteResolveValidator;
use app\service\payment\runtime\PaymentRouteService;
use support\Request;
use support\Response;

/**
 * 管理后台路由预览控制器。
 *
 * 负责按商户分组、支付方式和金额条件解析可用通道。
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
     * GET /admin/routes/resolve
     *
     * 解析路由结果。
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

