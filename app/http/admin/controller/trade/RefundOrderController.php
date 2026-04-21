<?php

namespace app\http\admin\controller\trade;

use app\common\base\BaseController;
use app\http\admin\validation\RefundActionValidator;
use app\http\admin\validation\RefundOrderValidator;
use app\service\payment\order\RefundService;
use support\Request;
use support\Response;

/**
 * 退款订单管理控制器。
 *
 * @property RefundService $refundService 退款服务
 */
class RefundOrderController extends BaseController
{
    /**
 * 构造方法。
     *
     * @param RefundService $refundService 退款服务
     * @return void
     */
    public function __construct(
        protected RefundService $refundService
    ) {
    }

    /**
     * 查询退款订单列表。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function index(Request $request): Response
    {
        $data = $this->validated($request->all(), RefundOrderValidator::class, 'index');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->success($this->refundService->paginate($data, $page, $pageSize));
    }

    /**
     * 查询退款订单详情。
     *
     * @param Request $request 请求对象
     * @param string $refundNo 退款单号
     * @return Response 响应对象
     */
    public function show(Request $request, string $refundNo): Response
    {
        return $this->success($this->refundService->detail($refundNo));
    }

    /**
     * 重试退款。
     *
     * @param Request $request 请求对象
     * @param string $refundNo 退款单号
     * @return Response 响应对象
     */
    public function retry(Request $request, string $refundNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['refund_no' => $refundNo]),
            RefundActionValidator::class,
            'retry'
        );

        return $this->success($this->refundService->retryRefund($refundNo, $data));
    }
}






