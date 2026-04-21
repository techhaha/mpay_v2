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
 * 负责退款单创建、查询和终态推进入口。
 *
 * @property RefundService $refundService 退款服务
 */
class RefundController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param RefundService $refundService 退款服务
     * @return void
     */
    public function __construct(
        protected RefundService $refundService,
    ) {
    }

    /**
     * 创建退款单。
     *
     * 以原支付单为基准创建退款申请，后续由退款生命周期服务推进处理中、成功或失败状态。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function create(Request $request): Response
    {
        $data = $this->validated($request->all(), RefundCreateValidator::class, 'store');

        return $this->success($this->refundService->createRefund($data));
    }

    /**
     * 查询退款单详情。
     *
     * 用于退款进度展示和后台对账。
     *
     * @param Request $request 请求对象
     * @param string $refundNo 退款单号
     * @return Response 响应对象
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
     *
     * 由渠道侧或任务调度侧在退款请求已经受理后调用，用于推进退款状态机。
     *
     * @param Request $request 请求对象
     * @param string $refundNo 退款单号
     * @return Response 响应对象
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
     *
     * 仅用于失败态退款单重新发起处理，保持退款链路幂等和可恢复。
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
     * 标记退款失败。
     *
     * 用于把退款单推进到终态失败，并记录失败原因供运营和对账排查。
     *
     * @param Request $request 请求对象
     * @param string $refundNo 退款单号
     * @return Response 响应对象
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






