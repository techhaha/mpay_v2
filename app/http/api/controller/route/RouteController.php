<?php

namespace app\http\api\controller\route;

use app\common\base\BaseController;
use app\http\api\validation\RouteResolveValidator;
use app\service\payment\runtime\PaymentRouteService;
use support\Request;
use support\Response;

/**
 * 路由解析控制器。
 *
 * 用于根据商户分组、支付方式和金额条件返回路由候选与最终选中通道。
 *
 * @property PaymentRouteService $paymentRouteService 支付路由服务
 */
class RouteController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param PaymentRouteService $paymentRouteService 支付路由服务
     * @return void
     */
    public function __construct(
        protected PaymentRouteService $paymentRouteService
    ) {
    }

    /**
     * 解析支付路由。
     *
     * 这个接口会返回当前条件下的候选通道和最终命中的通道信息，通常用于下单前查看结果。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
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






