<?php

namespace app\http\api\controller\epay;

use app\common\base\BaseController;
use app\service\payment\epay\EpayV1ProtocolService;
use app\http\api\validation\EpayV1Validator;
use support\limiter\Limiter;
use support\Request;
use support\Response;
use Throwable;

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
        $payload = $request->all();

        try {
            Limiter::check('epay-v1-submit-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
            if ((int) ($payload['pid'] ?? 0) > 0) {
                Limiter::check('epay-v1-submit-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
            }
            $payload = $this->validated($payload, EpayV1Validator::class, 'submit');

            return $this->epayV1ProtocolService->submit($payload, $request);
        } catch (Throwable $e) {
            return $this->epayV1ProtocolService->entryErrorResponse($payload, $e);
        }
    }

    /**
     * API 支付入口。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function mapi(Request $request): Response
    {
        $payload = $request->all();
        Limiter::check('epay-v1-mapi-ip:' . $request->getRealIp(), 120, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v1-mapi-merchant:' . (int) $payload['pid'], 60, 60, '商户接口请求过于频繁，请稍后再试');
        }
        $payload = $this->validated($payload, EpayV1Validator::class, 'mapi');

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
        Limiter::check('epay-v1-api-ip:' . $request->getRealIp(), 300, 60, '接口请求过于频繁，请稍后再试');
        if ((int) ($payload['pid'] ?? 0) > 0) {
            Limiter::check('epay-v1-api-merchant:' . (int) $payload['pid'], 180, 60, '商户接口请求过于频繁，请稍后再试');
        }

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
