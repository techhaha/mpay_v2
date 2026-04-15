<?php

namespace app\http\api\controller\trade;

use app\common\base\BaseController;
use app\exception\ResourceNotFoundException;
use app\http\api\validation\RefundActionValidator;
use app\http\api\validation\RefundCreateValidator;
use app\service\payment\order\RefundService;
use support\Request;
use support\Response;

/**
 * 退款接口控制器。
 *
 * 负责退款单创建与退款单查询。
 */
class RefundController extends BaseController
{
    /**
     * 构造函数，注入退款相关依赖。
     */
    public function __construct(
        protected RefundService $refundService,
    ) {
    }

    /**
     * 创建退款单。
     */
    public function create(Request $request): Response
    {
        $data = $this->validated($request->all(), RefundCreateValidator::class, 'store');

        return $this->success($this->refundService->createRefund($data));
    }

    /**
     * 查询退款单详情。
     */
    public function show(Request $request, string $refundNo): Response
    {
        try {
            return $this->success($this->refundService->detail($refundNo));
        } catch (ResourceNotFoundException) {
            return $this->fail('退款单不存在', 404);
        }
    }

    /**
     * 标记退款处理中。
     */
    public function processing(Request $request, string $refundNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['refund_no' => $refundNo]),
            RefundActionValidator::class,
            'processing'
        );

        return $this->success($this->refundService->markRefundProcessing($refundNo, $data));
    }

    /**
     * 退款重试。
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
     * 标记退款失败。
     */
    public function markFail(Request $request, string $refundNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['refund_no' => $refundNo]),
            RefundActionValidator::class,
            'fail'
        );

        return $this->success($this->refundService->markRefundFailed($refundNo, $data));
    }

}

