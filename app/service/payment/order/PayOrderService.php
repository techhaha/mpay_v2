<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\model\payment\PayOrder;
use support\Request;
use support\Response;

/**
 * 支付单服务。
 *
 * @property PayOrderQueryService $queryService 查询服务
 * @property PayOrderAttemptService $attemptService 发起服务
 * @property PayOrderLifecycleService $lifecycleService 生命周期服务
 * @property PayOrderCallbackService $callbackService 回调服务
 */
class PayOrderService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderQueryService $queryService 查询服务
     * @param PayOrderAttemptService $attemptService 发起服务
     * @param PayOrderLifecycleService $lifecycleService 生命周期服务
     * @param PayOrderCallbackService $callbackService 回调服务
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
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户ID
     * @return array 分页数据
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
    {
        return $this->queryService->paginate($filters, $page, $pageSize, $merchantId);
    }

    /**
     * 查询支付订单详情。
     *
     * @param string $payNo 支付单号
     * @param int|null $merchantId 商户ID
     * @return array 订单详情
     */
    public function detail(string $payNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($payNo, $merchantId);
    }

    /**
     * 预创建支付尝试。
     *
     * @param array $input 下单数据
     * @return array 发起结果
     */
    public function preparePayAttempt(array $input): array
    {
        return $this->attemptService->preparePayAttempt($input);
    }

    /**
     * 预创建收银台业务单。
     *
     * @param array $input 收银台数据
     * @return array 发起结果
     */
    public function prepareCashierBizOrder(array $input): array
    {
        return $this->attemptService->prepareCashierBizOrder($input);
    }

    /**
     * 标记支付成功。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function markPaySuccess(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPaySuccess($payNo, $input);
    }

    /**
     * 在当前事务中标记支付成功。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function markPaySuccessInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPaySuccessInCurrentTransaction($payNo, $input);
    }

    /**
     * 标记支付失败。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function markPayFailed(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPayFailed($payNo, $input);
    }

    /**
     * 在当前事务中标记支付失败。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function markPayFailedInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->markPayFailedInCurrentTransaction($payNo, $input);
    }

    /**
     * 关闭支付单。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function closePayOrder(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->closePayOrder($payNo, $input);
    }

    /**
     * 在当前事务中关闭支付单。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function closePayOrderInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->closePayOrderInCurrentTransaction($payNo, $input);
    }

    /**
     * 标记支付超时。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function timeoutPayOrder(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->timeoutPayOrder($payNo, $input);
    }

    /**
     * 在当前事务中标记支付超时。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function timeoutPayOrderInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        return $this->lifecycleService->timeoutPayOrderInCurrentTransaction($payNo, $input);
    }

    /**
     * 处理渠道回调。
     *
     * @param array $input 回调数据
     * @return PayOrder 支付订单模型
     */
    public function handleChannelCallback(array $input): PayOrder
    {
        return $this->callbackService->handleChannelCallback($input);
    }

    /**
     * 按支付单号处理真实第三方回调。
     *
     * @param string $payNo 支付单号
     * @param Request $request 请求对象
     * @return string|Response 字符串或响应对象
     */
    public function handlePluginCallback(string $payNo, Request $request): string|Response
    {
        return $this->callbackService->handlePluginCallback($payNo, $request);
    }
}


