<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\model\payment\PayOrder;
use support\Request;
use support\Response;

/**
 * 支付单门面服务。
 *
 * 对外保留原有调用契约，内部委托给查询、发起、生命周期和回调四个子服务。
 */
class PayOrderService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected PayOrderQueryService $queryService,
        protected PayOrderAttemptService $attemptService,
        protected PayOrderLifecycleService $lifecycleService,
        protected PayOrderCallbackService $callbackService
    ) {
    }

    /**
     * 分页查询支付订单列表。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
    {
        return $this->queryService->paginate($filters, $page, $pageSize, $merchantId);
    }

    /**
     * 查询支付订单详情。
     */
    public function detail(string $payNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($payNo, $merchantId);
    }

    /**
     * 预创建支付尝试。
     */
    public function preparePayAttempt(array $input): array
    {
        return $this->attemptService->preparePayAttempt($input);
    }

    /**
     * 标记支付成功。
     */
    public function markPaySuccess(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPaySuccess($payNo, $input);
    }

    /**
     * 在当前事务中标记支付成功。
     */
    public function markPaySuccessInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPaySuccessInCurrentTransaction($payNo, $input);
    }

    /**
     * 标记支付失败。
     */
    public function markPayFailed(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPayFailed($payNo, $input);
    }

    /**
     * 在当前事务中标记支付失败。
     */
    public function markPayFailedInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPayFailedInCurrentTransaction($payNo, $input);
    }

    /**
     * 关闭支付单。
     */
    public function closePayOrder(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->closePayOrder($payNo, $input);
    }

    /**
     * 在当前事务中关闭支付单。
     */
    public function closePayOrderInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->closePayOrderInCurrentTransaction($payNo, $input);
    }

    /**
     * 标记支付超时。
     */
    public function timeoutPayOrder(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->timeoutPayOrder($payNo, $input);
    }

    /**
     * 在当前事务中标记支付超时。
     */
    public function timeoutPayOrderInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->timeoutPayOrderInCurrentTransaction($payNo, $input);
    }

    /**
     * 处理渠道回调。
     */
    public function handleChannelCallback(array $input): PayOrder
    {
        return $this->callbackService->handleChannelCallback($input);
    }

    /**
     * 按支付单号处理真实第三方回调。
     */
    public function handlePluginCallback(string $payNo, Request $request): string|Response
    {
        return $this->callbackService->handlePluginCallback($payNo, $request);
    }
}
