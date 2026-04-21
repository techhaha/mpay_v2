<?php

namespace app\http\api\controller\adapter;

use app\common\base\BaseController;
use app\exception\ValidationException;
use app\http\api\validation\EpayValidator;
use app\service\payment\compat\EpayCompatService;
use support\Request;
use support\Response;

/**
 * Epay 协议兼容控制器。
 *
 * 负责兼容入口场景的校验与结果分发。
 *
 * @property EpayCompatService $epayCompatService Epay 兼容服务
 */
class EpayController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param EpayCompatService $epayCompatService Epay 兼容服务
     * @return void
     */
    public function __construct(
        protected EpayCompatService $epayCompatService
    ) {}

    /**
     * 页面跳转支付入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function submit(Request $request): Response
    {
        try {
            $payload = $this->validated($request->all(), EpayValidator::class, 'submit');

            return $this->epayCompatService->submit($payload, $request);
        } catch (ValidationException $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage() ?: '提交失败',
            ]);
        }
    }

    /**
     * API 接口支付入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function mapi(Request $request): Response
    {
        try {
            $payload = $this->validated($request->all(), EpayValidator::class, 'mapi');

            return json($this->epayCompatService->mapi($payload, $request));
        } catch (ValidationException $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage() ?: '提交失败',
            ]);
        }
    }

    /**
     * 标准 API 接口入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function api(Request $request): Response
    {
        try {
            $payload = $request->all();
            $act = strtolower(trim((string) ($payload['act'] ?? '')));
            $scene = match ($act) {
                'settle' => 'settle',
                'orders' => 'orders',
                'order' => trim((string) ($payload['trade_no'] ?? '')) !== '' ? 'order_trade_no' : 'order_out_trade_no',
                'refund' => trim((string) ($payload['trade_no'] ?? '')) !== '' ? 'refund_trade_no' : 'refund_out_trade_no',
                default => 'query',
            };
            $payload = $this->validated($payload, EpayValidator::class, $scene);

            return json($this->epayCompatService->api($payload));
        } catch (ValidationException $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage() ?: '请求失败',
            ]);
        }
    }
}





