<?php

namespace app\http\admin\controller;

use app\common\base\BaseController;
use app\repositories\PaymentMethodRepository;
use app\repositories\PaymentOrderRepository;
use app\services\PayOrderService;
use support\Request;

/**
 * 订单管理
 */
class OrderController extends BaseController
{
    public function __construct(
        protected PaymentOrderRepository $orderRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PayOrderService $payOrderService,
    ) {
    }

    /**
     * GET /adminapi/order/list
     */
    public function list(Request $request)
    {
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $methodCode = trim((string)$request->get('method_code', ''));
        $methodId = 0;
        if ($methodCode !== '') {
            $method = $this->methodRepository->findAnyByCode($methodCode);
            $methodId = $method ? (int)$method->id : 0;
        }

        $filters = [
            'merchant_id' => (int)$request->get('merchant_id', 0),
            'merchant_app_id' => (int)$request->get('merchant_app_id', 0),
            'method_id' => $methodId,
            'channel_id' => (int)$request->get('channel_id', 0),
            'status' => $request->get('status', ''),
            'order_id' => trim((string)$request->get('order_id', '')),
            'mch_order_no' => trim((string)$request->get('mch_order_no', '')),
            'created_from' => trim((string)$request->get('created_from', '')),
            'created_to' => trim((string)$request->get('created_to', '')),
        ];

        $paginator = $this->orderRepository->searchPaginate($filters, $page, $pageSize);
        return $this->page($paginator);
    }

    /**
     * GET /adminapi/order/detail?id=1 或 order_id=P...
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        $orderId = trim((string)$request->get('order_id', ''));

        if ($id > 0) {
            $row = $this->orderRepository->find($id);
        } elseif ($orderId !== '') {
            $row = $this->orderRepository->findByOrderId($orderId);
        } else {
            return $this->fail('参数错误', 400);
        }

        if (!$row) {
            return $this->fail('订单不存在', 404);
        }

        return $this->success($row);
    }

    /**
     * POST /adminapi/order/refund
     * - order_id: 系统订单号
     * - refund_amount: 退款金额
     */
    public function refund(Request $request)
    {
        $orderId = trim((string)$request->post('order_id', ''));
        $refundAmount = (float)$request->post('refund_amount', 0);
        $refundReason = trim((string)$request->post('refund_reason', ''));

        try {
            $result = $this->payOrderService->refundOrder([
                'order_id' => $orderId,
                'refund_amount' => $refundAmount,
                'refund_reason' => $refundReason,
            ]);
            return $this->success($result, '退款发起成功');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }
}

