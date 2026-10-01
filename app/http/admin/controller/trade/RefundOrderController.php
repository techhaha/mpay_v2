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
     * 重新派发待处理退款，或重试失败退款。
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

    /**
     * 主动核对原退款单的渠道退款状态。
     *
     * 仅供管理后台核实处理中退款，结果由退款服务按渠道可信事实推进。
     *
     * @param Request $request 管理员请求
     * @param string $refundNo 原退款单号
     * @return Response 更新后的退款详情
     */
    public function query(Request $request, string $refundNo): Response
    {
        $this->validated(['refund_no' => $refundNo], RefundActionValidator::class, 'query');

        return $this->success($this->refundService->queryRefundStatus($refundNo));
    }

    /** 人工核实原退款单已实际成功，并保留管理员提交的外部证据。 */
    public function confirmSuccess(Request $request, string $refundNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['refund_no' => $refundNo]),
            RefundActionValidator::class,
            'confirm_success'
        );

        return $this->success($this->refundService->confirmRefundSuccess($refundNo, $data, $this->currentAdminId($request)));
    }

    /** 取消尚未派发的原退款单。 */
    public function cancel(Request $request, string $refundNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['refund_no' => $refundNo]),
            RefundActionValidator::class,
            'cancel'
        );

        return $this->success($this->refundService->cancelRefund($refundNo, $data['reason'], $this->currentAdminId($request)));
    }
}






