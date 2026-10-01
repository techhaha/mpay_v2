<?php

namespace app\http\admin\controller\trade;

use app\common\base\BaseController;
use app\exception\UnauthorizedException;
use app\http\admin\validation\PayOrderActionValidator;
use app\http\admin\validation\PayOrderValidator;
use app\service\payment\order\PayOrderAdminActionService;
use app\service\payment\order\PayOrderService;
use support\Request;
use support\Response;

/**
 * 支付订单管理控制器。
 *
 * 负责订单查询和管理员操作的身份确认、参数校验与响应包装；状态和资金交给业务服务。
 *
 * @property PayOrderService $payOrderService 支付订单服务
 * @property PayOrderAdminActionService $payOrderAdminActionService 支付订单后台操作服务
 */
class PayOrderController extends BaseController
{
    /**
     * 管理员全额补扣原支付单待收服务费。
     *
     * 必须有真实管理员身份，包括开发环境放宽中间件时；金额取订单快照，
     * 入口只接收操作原因，不允许请求金额或请求中的单号覆盖路径目标。
     *
     * @throws UnauthorizedException 没有当前管理员身份
     */
    public function collectServiceFee(Request $request, string $payNo): Response
    {
        $adminId = $this->currentAdminId($request);
        if ($adminId <= 0) {
            throw new UnauthorizedException('管理员未授权');
        }
        $data = $this->validated(
            array_replace($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'collect_service_fee'
        );
        return $this->success($this->payOrderAdminActionService->collectServiceFee($payNo, $data, $adminId));
    }

    /**
     * 构造方法。
     *
     * @param PayOrderService $payOrderService 支付订单服务
     * @param PayOrderAdminActionService $payOrderAdminActionService 支付订单后台操作服务
     * @return void
     */
    public function __construct(
        protected PayOrderService $payOrderService,
        protected PayOrderAdminActionService $payOrderAdminActionService
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

        return $this->success($this->payOrderService->paginate($data, $page, $pageSize, null, true));
    }

    /**
     * 查询支付订单详情。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function show(Request $request, string $payNo): Response
    {
        $this->validated(
            array_merge($request->all(), ['pay_no' => $payNo]),
            PayOrderValidator::class,
            'show'
        );

        return $this->success($this->payOrderService->detail($payNo, null, true));
    }

    /**
     * 查询支付订单可操作项。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function actions(Request $request, string $payNo): Response
    {
        $this->validated(
            array_merge($request->all(), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'actions'
        );

        return $this->success($this->payOrderAdminActionService->actions($payNo));
    }

    /**
     * 重新通知商户。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function renotify(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'renotify'
        );

        return $this->success($this->payOrderAdminActionService->renotify($payNo, $data, $this->currentAdminId($request)));
    }

    /**
     * 主动查询上游支付结果。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function activeQuery(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'active_query'
        );

        return $this->success($this->payOrderAdminActionService->activeQuery($payNo, $data, $this->currentAdminId($request)));
    }

    /**
     * 发起 API 退款。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function apiRefund(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'api_refund'
        );

        return $this->success($this->payOrderAdminActionService->apiRefund($payNo, $data, $this->currentAdminId($request)));
    }

    /**
     * 手动退款。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function manualRefund(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'manual_refund'
        );

        return $this->success($this->payOrderAdminActionService->manualRefund($payNo, $data, $this->currentAdminId($request)));
    }

    /**
     * 手动补单。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function manualSuccess(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'manual_success'
        );

        return $this->success($this->payOrderAdminActionService->manualSuccess($payNo, $data, $this->currentAdminId($request)));
    }

    /**
     * 冻结支付订单。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function freeze(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'freeze'
        );

        return $this->success($this->payOrderAdminActionService->freeze($payNo, $data, $this->currentAdminId($request)));
    }

    /**
     * 解冻支付订单。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function unfreeze(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($this->payload($request), ['pay_no' => $payNo]),
            PayOrderActionValidator::class,
            'unfreeze'
        );

        return $this->success($this->payOrderAdminActionService->unfreeze($payNo, $data, $this->currentAdminId($request)));
    }
}






