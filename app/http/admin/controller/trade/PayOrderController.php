<?php

namespace app\http\admin\controller\trade;

use app\common\base\BaseController;
use app\http\admin\validation\PayOrderValidator;
use app\service\payment\order\PayOrderService;
use support\Request;
use support\Response;

/**
 * 支付订单管理控制器。
 *
 * 当前先提供列表查询，后续可以继续扩展支付单详情、关闭、重试等管理操作。
 *
 * @property PayOrderService $payOrderService 支付订单服务
 */
class PayOrderController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param PayOrderService $payOrderService 支付订单服务
     * @return void
     */
    public function __construct(
        protected PayOrderService $payOrderService
    ) {
    }

    /**
     * 查询支付订单列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), PayOrderValidator::class, 'index');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->success($this->payOrderService->paginate($data, $page, $pageSize));
    }
}






