<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\model\payment\RefundOrder;

/**
 * 退款单门面服务。
 *
 * 对外保留原有调用契约，内部委托给查询、创建和生命周期三个子服务。
 */
class RefundService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected RefundQueryService $queryService,
        protected RefundCreationService $creationService,
        protected RefundLifecycleService $lifecycleService
    ) {
    }

    /**
     * 分页查询退款订单列表。
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
    {
        return $this->queryService->paginate($filters, $page, $pageSize, $merchantId);
    }

    /**
     * 查询退款订单详情。
     */
    public function detail(string $refundNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($refundNo, $merchantId);
    }

    /**
     * 创建退款单。
     */
    public function createRefund(array $input): RefundOrder
    {
        return $this->creationService->createRefund($input);
    }

    /**
     * 标记退款处理中。
     */
    public function markRefundProcessing(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundProcessing($refundNo, $input);
    }

    /**
     * 退款重试。
     */
    public function retryRefund(string $refundNo, array $input = [], ?int $merchantId = null): RefundOrder
    {
        if ($merchantId !== null && $merchantId > 0) {
            $refundOrder = $this->queryService->findByRefundNo($refundNo, $merchantId);
            if (!$refundOrder) {
                throw new \app\exception\ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
        }

        return $this->lifecycleService->retryRefund($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款处理中或重试。
     */
    public function markRefundProcessingInCurrentTransaction(string $refundNo, array $input = [], bool $isRetry = false): RefundOrder
    {
        return $this->lifecycleService->markRefundProcessingInCurrentTransaction($refundNo, $input, $isRetry);
    }

    /**
     * 退款成功。
     */
    public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundSuccess($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款成功。
     */
    public function markRefundSuccessInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundSuccessInCurrentTransaction($refundNo, $input);
    }

    /**
     * 退款失败。
     */
    public function markRefundFailed(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundFailed($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款失败。
     */
    public function markRefundFailedInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundFailedInCurrentTransaction($refundNo, $input);
    }
}
