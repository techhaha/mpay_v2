<?php

namespace app\http\mer\controller\trade;

use app\common\base\BaseController;
use app\http\mer\validation\RefundActionValidator;
use app\http\mer\validation\RefundOrderValidator;
use app\service\payment\order\RefundService;
use support\Request;
use support\Response;

/**
 * 商户后台退款订单控制器。
 */
class RefundOrderController extends BaseController
{
    public function __construct(
        protected RefundService $refundService
    ) {
    }

    /**
     * 查询当前商户的退款订单列表。
     */
    public function index(Request $request): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated($request->all(), RefundOrderValidator::class, 'index');
        $data['merchant_id'] = $merchantId;
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['page_size'] ?? 10));

        return $this->success($this->refundService->paginate($data, $page, $pageSize, $merchantId));
    }

    /**
     * 查询当前商户的退款订单详情。
     */
    public function show(Request $request, string $refundNo): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        return $this->success($this->refundService->detail($refundNo, $merchantId));
    }

    /**
     * 重试当前商户的退款单。
     */
    public function retry(Request $request, string $refundNo): Response
    {
        $merchantId = $this->currentMerchantId($request);
        if ($merchantId <= 0) {
            return $this->fail('未获取到当前商户信息', 401);
        }

        $data = $this->validated(
            array_merge($request->all(), ['refund_no' => $refundNo]),
            RefundActionValidator::class,
            'retry'
        );

        return $this->success($this->refundService->retryRefund($refundNo, $data, $merchantId));
    }
}

