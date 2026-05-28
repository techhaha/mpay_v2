<?php

namespace app\http\api\controller\epay;

use app\common\base\BaseController;
use app\http\api\validation\EpayV2Validator;
use app\service\payment\epay\EpayV2ProtocolService;
use app\service\payment\order\PayOrderService;
use support\limiter\Limiter;
use support\Request;
use support\Response;
use Throwable;

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
        $payload = $request->all();

        try {
            Limiter::check('epay-v2-pay-submit-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
            if ((int) ($payload['pid'] ?? 0) > 0) {
                Limiter::check('epay-v2-pay-submit-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
            }
            $payload = $this->validated($payload, EpayV2Validator::class, 'submit');

            return $this->epayV2ProtocolService->submit($payload, $request);
        } catch (Throwable $e) {
            return $this->epayV2ProtocolService->entryErrorResponse($payload, $e);
        }
    }

    /**
     * API 下单入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function create(Request $request): Response
    {
        $payload = $request->all();
        Limiter::check('epay-v2-pay-create-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-pay-create-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'create');

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
        $payload = $request->all();
        Limiter::check('epay-v2-pay-query-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-pay-query-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'query');

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
        $payload = $request->all();
        Limiter::check('epay-v2-pay-refund-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-pay-refund-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'refund');

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
        $payload = $request->all();
        Limiter::check('epay-v2-pay-refund-query-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-pay-refund-query-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'refund_query');

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
        $payload = $request->all();
        Limiter::check('epay-v2-pay-close-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-pay-close-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'close');

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
        $payload = $request->all();
        Limiter::check('epay-v2-merchant-info-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-merchant-info-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'merchant_info');

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
        $payload = $request->all();
        Limiter::check('epay-v2-merchant-orders-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-merchant-orders-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'merchant_orders');

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
        $payload = $request->all();
        Limiter::check('epay-v2-transfer-submit-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-transfer-submit-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'transfer_submit');

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
        $payload = $request->all();
        Limiter::check('epay-v2-transfer-query-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-transfer-query-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'transfer_query');

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
        $payload = $request->all();
        Limiter::check('epay-v2-transfer-balance-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v2-transfer-balance-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV2Validator::class, 'transfer_balance');

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

    /**
     * 通道级通知入口。
     *
     * @param Request $request 请求对象
     * @param int $chanId 通道ID
     * @return string|Response
     */
    public function channelNotify(Request $request, int $chanId): string|Response
    {
        return $this->payOrderService->handleChannelNotify($chanId, $request);
    }

}
