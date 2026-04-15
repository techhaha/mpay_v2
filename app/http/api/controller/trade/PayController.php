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
 * 支付接口控制器。
 *
 * 负责支付预下单、支付查询、支付关闭和渠道回调入口。
 */
class PayController extends BaseController
{
    /**
     * 构造函数，注入支付单相关依赖。
     */
    public function __construct(
        protected PayOrderService $payOrderService,
        protected MerchantApiCredentialService $merchantApiCredentialService,
        protected PaymentTypeService $paymentTypeService
    ) {
    }

    /**
     * 支付预下单。
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
     * 处理渠道回调。
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
     */
    private function normalizePreparePayload(Request $request, array $payload): array
    {
        $this->merchantApiCredentialService->verifyMd5Sign($payload);

        $typeCode = trim((string) ($payload['type'] ?? ''));
        $paymentType = $this->paymentTypeService->resolveEnabledType($typeCode);
        $typeCode = (string) $paymentType->code;

        $money = (string) ($payload['money'] ?? '0');
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
                'channel_callback_base_url' => (string) sys_config('site_url') . '/api/pay',
            ],
        ];
    }
}

