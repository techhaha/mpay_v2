<?php

namespace app\http\api\controller\trade;

use app\common\base\BaseController;
use app\exception\ResourceNotFoundException;
use app\http\api\validation\PayCallbackValidator;
use app\http\api\validation\PayCloseValidator;
use app\http\api\validation\PayPrepareValidator;
use app\http\api\validation\PayTimeoutValidator;
use app\service\merchant\security\MerchantApiCredentialService;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\order\PayOrderService;
use support\Request;
use support\Response;

/**
 * 收银台支付接口控制器。
 *
 * 负责支付预下单、支付单查询、支付关闭、超时收口和渠道回调入口。
 *
 * @property PayOrderService $payOrderService 支付订单服务
 * @property MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
 * @property PaymentTypeService $paymentTypeService 支付方式服务
 */
class PayController extends BaseController
{
    /**
     * 构造方法。
     *
     * @param PayOrderService $payOrderService 支付订单服务
     * @param MerchantApiCredentialService $merchantApiCredentialService 商户 API 凭证服务
     * @param PaymentTypeService $paymentTypeService 支付类型服务
     * @return void
     */
    public function __construct(
        protected PayOrderService $payOrderService,
        protected MerchantApiCredentialService $merchantApiCredentialService,
        protected PaymentTypeService $paymentTypeService
    ) {
    }

    /**
     * 创建支付预下单并返回支付尝试结果。
     *
     * 先对外部支付参数完成验签和归一化，再交给支付单尝试服务选择路由并创建支付单。
     *
     * @param Request $request 请求对象
     * @return Response 响应对象
     */
    public function prepare(Request $request): Response
    {
        $data = $this->validated(
            $this->normalizePreparePayload($request, $request->all()),
            PayPrepareValidator::class,
            'prepare'
        );

        return $this->success($this->payOrderService->preparePayAttempt($data));
    }

    /**
     * 查询支付单详情。
     *
     * 用于前端轮询支付结果或展示支付单当前状态。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function show(Request $request, string $payNo): Response
    {
        try {
            return $this->success($this->payOrderService->detail($payNo));
        } catch (ResourceNotFoundException) {
            return $this->fail('支付单不存在', 404);
        }
    }

    /**
     * 关闭支付单。
     *
     * 仅对尚未完成的支付单生效，通常由业务系统在用户主动取消时调用。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function close(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['pay_no' => $payNo]),
            PayCloseValidator::class,
            'close'
        );

        return $this->success($this->payOrderService->closePayOrder($payNo, $data));
    }

    /**
     * 标记支付单超时。
     *
     * 用于订单到达超时时间后的状态收口，后续由生命周期服务统一处理手续费释放和订单同步。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response 响应对象
     */
    public function timeout(Request $request, string $payNo): Response
    {
        $data = $this->validated(
            array_merge($request->all(), ['pay_no' => $payNo]),
            PayTimeoutValidator::class,
            'timeout'
        );

        return $this->success($this->payOrderService->timeoutPayOrder($payNo, $data));
    }

    /**
     * 处理支付回调。
     *
     * 当路径里携带 `payNo` 时，进入第三方插件回调链路；
     * 当未携带 `payNo` 时，按平台统一回调载荷进入渠道回调处理。
     *
     * @param Request $request 请求对象
     * @param string $payNo 支付单号
     * @return Response|string 字符串或响应对象
     */
    public function callback(Request $request, string $payNo = ''): Response|string
    {
        if ($payNo !== '') {
            return $this->payOrderService->handlePluginCallback($payNo, $request);
        }

        $data = $this->validated($request->all(), PayCallbackValidator::class, 'callback');

        return $this->success($this->payOrderService->handleChannelCallback($data));
    }

    /**
     * 归一化外部支付下单参数并完成签名校验。
     *
     * 这层逻辑保留在控制器内，避免中间件承担业务验签职责。
     * 同时把外部字段映射为系统内部支付单入参，并将回调基址写入扩展信息。
     *
     * @param Request $request 请求对象
     * @param array $payload 请求载荷
     * @return array 支付下单参数
     * @throws \app\exception\ResourceNotFoundException
     * @throws \app\exception\ValidationException
     */
    private function normalizePreparePayload(Request $request, array $payload): array
    {
        $this->merchantApiCredentialService->verifyMd5Sign($payload);

        $typeCode = trim((string) ($payload['type'] ?? ''));
        $paymentType = $this->paymentTypeService->resolveEnabledType($typeCode);
        $typeCode = (string) $paymentType->code;

        $money = (string) ($payload['money'] ?? '0');
        // 外部协议按“元”传金额，系统内部统一转成“分”存储和计算。
        $amount = (int) round(((float) $money) * 100);

        return [
            'merchant_id' => (int) ($payload['pid'] ?? 0),
            'merchant_order_no' => (string) ($payload['out_trade_no'] ?? ''),
            'pay_type_id' => (int) $paymentType->id,
            'pay_amount' => $amount,
            'subject' => (string) ($payload['name'] ?? ''),
            'body' => (string) ($payload['name'] ?? ''),
            'ext_json' => [
                'type_code' => $typeCode,
                'notify_url' => (string) ($payload['notify_url'] ?? ''),
                'return_url' => (string) ($payload['return_url'] ?? ''),
                'param' => $payload['param'] ?? null,
                'clientip' => (string) ($payload['clientip'] ?? ''),
                'device' => (string) ($payload['device'] ?? ''),
                'sign_type' => (string) ($payload['sign_type'] ?? 'MD5'),
                // 回调基址会被插件和支付单后续流程复用。
                'channel_callback_base_url' => (string) sys_config('site_url') . '/api/pay',
            ],
        ];
    }
}







