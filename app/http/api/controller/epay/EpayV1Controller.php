<?php

namespace app\http\api\controller\epay;

use app\common\base\BaseController;
use app\service\payment\epay\EpayV1ProtocolService;
use app\http\api\validation\EpayV1Validator;
use support\Request;
use support\Response;

/**
 * ePay V1 控制器。
 *
 * 负责承接旧版页面跳转、API 支付与旧接口兼容查询。
 */
class EpayV1Controller extends BaseController
{
    /**
     * 构造方法。
     *
     * @param EpayV1ProtocolService $epayV1ProtocolService V1 协议服务
     */
    public function __construct(
        protected EpayV1ProtocolService $epayV1ProtocolService
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
        $payload = $this->validated($request->all(), EpayV1Validator::class, 'submit');
        return $this->epayV1ProtocolService->submit($payload, $request);
    }

    /**
     * API 支付入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function mapi(Request $request): Response
    {
        $payload = $this->validated($request->all(), EpayV1Validator::class, 'mapi');
        return json($this->epayV1ProtocolService->mapi($payload, $request));
    }

    /**
     * 旧版兼容 API 入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function api(Request $request): Response
    {
        $payload = $request->all();
        $scene = $this->resolveApiScene((string) ($payload['act'] ?? ''));
        if ($scene === null) {
            return json(['code' => 0, 'msg' => '不支持的操作类型']);
        }

        $payload = $this->validated($payload, EpayV1Validator::class, $scene);
        return json($this->epayV1ProtocolService->api($payload));
    }

    /**
     * 映射旧版 `act` 到验证场景。
     *
     * @param string $act 接口动作
     * @return string|null 验证场景
     */
    private function resolveApiScene(string $act): ?string
    {
        return match (strtolower(trim($act))) {
            'query' => 'api_query',
            'settle' => 'api_settle',
            'order' => 'api_order',
            'orders' => 'api_orders',
            'refund' => 'api_refund',
            default => null,
        };
    }
}
