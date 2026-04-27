<?php

namespace app\http\api\controller\epay;

use app\common\base\BaseController;
use app\http\api\validation\EpayV2Validator;
use app\service\payment\epay\EpayV2ProtocolService;
use app\service\payment\order\PayOrderService;
use support\Request;
use support\Response;

/**
 * ePay V2 控制器。
 *
 * 负责承接新版支付、查询、退款、商户与转账接口。
 */
class EpayV2Controller extends BaseController
{
    /**
     * 构造方法。
     *
     * @param EpayV2ProtocolService $epayV2ProtocolService V2 协议服务
     */
    public function __construct(
        protected EpayV2ProtocolService $epayV2ProtocolService,
        protected PayOrderService $payOrderService
    ) {
    }

    /**
     * 页面跳转支付入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function submit(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'submit');
        return $this->epayV2ProtocolService->submit($payload, $request);
    }

    /**
     * API 下单入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function create(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'create');
        return json($this->epayV2ProtocolService->create($payload, $request));
    }

    /**
     * 支付单查询入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function query(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'query');
        return json($this->epayV2ProtocolService->query($payload));
    }

    /**
     * 退款发起入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function refund(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'refund');
        return json($this->epayV2ProtocolService->refund($payload));
    }

    /**
     * 退款查询入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function refundQuery(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'refund_query');
        return json($this->epayV2ProtocolService->refundQuery($payload));
    }

    /**
     * 关闭订单入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function close(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'close');
        return json($this->epayV2ProtocolService->close($payload));
    }

    /**
     * 商户信息查询入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function merchantInfo(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'merchant_info');
        return json($this->epayV2ProtocolService->merchantInfo($payload));
    }

    /**
     * 商户订单列表入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function merchantOrders(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'merchant_orders');
        return json($this->epayV2ProtocolService->merchantOrders($payload));
    }

    /**
     * 转账发起入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function transferSubmit(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'transfer_submit');
        return json($this->epayV2ProtocolService->transferSubmit($payload));
    }

    /**
     * 转账查询入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function transferQuery(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'transfer_query');
        return json($this->epayV2ProtocolService->transferQuery($payload));
    }

    /**
     * 转账余额查询入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function transferBalance(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV2Validator::class, 'transfer_balance');
        return json($this->epayV2ProtocolService->transferBalance($payload));
    }

    /**
     * 渠道回调入口。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return string|Response
     */
    public function callback(Request $request, string $payNo): string|Response
    {
        return $this->payOrderService->handlePluginCallback($payNo, $request);
    }
}
