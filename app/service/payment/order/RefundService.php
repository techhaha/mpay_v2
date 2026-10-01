<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\RefundOrder;

/**
 * 退款单服务。
 *
 * @property RefundQueryService $queryService 查询服务
 * @property RefundCreationService $creationService 创建服务
 * @property RefundLifecycleService $lifecycleService 生命周期服务
 */
class RefundService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param RefundQueryService $queryService 查询服务
     * @param RefundCreationService $creationService 创建服务
     * @param RefundLifecycleService $lifecycleService 生命周期服务
     * @return void
     */
    public function __construct(
        protected RefundQueryService $queryService,
        protected RefundCreationService $creationService,
        protected RefundLifecycleService $lifecycleService,
        protected RefundDispatchService $dispatchService
    ) {
    }

    /**
     * 分页查询退款订单列表。
     *
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param int|null $merchantId 商户ID
     * @return array{list: array<int, array<string, mixed>>, total: int, page: int, size: int, pay_types: array<int, array{label: string, value: int}>} 退款列表结构
     */
    public function paginate(array $filters = [], int $page = 1, int $pageSize = 10, ?int $merchantId = null): array
    {
        return $this->queryService->paginate($filters, $page, $pageSize, $merchantId);
    }

    /**
     * 查询退款订单详情。
     *
     * @param string $refundNo 退款单号
     * @param int|null $merchantId 商户ID
     * @return array{refund_order: array<string, mixed>, timeline: array<int, array<string, mixed>>, account_ledgers: array<int, array<string, mixed>>} 退款详情结构
     */
    public function detail(string $refundNo, ?int $merchantId = null): array
    {
        return $this->queryService->detail($refundNo, $merchantId);
    }

    /**
     * 创建退款单。
     *
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function createRefund(array $input): RefundOrder
    {
        return $this->creationService->createRefund($input);
    }

    /**
     * 标记退款处理中。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundProcessing(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundProcessing($refundNo, $input);
    }

    /**
     * 重新派发待处理退款，或重试失败退款。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @param int|null $merchantId 商户ID
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function retryRefund(string $refundNo, array $input = [], ?int $merchantId = null): RefundOrder
    {
        $refundOrder = $this->queryService->findByRefundNo($refundNo, $merchantId);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $status = (int) $refundOrder->status;
        $extJson = (array) ($refundOrder->ext_json ?? []);
        $adminAction = (array) ($extJson['admin_action'] ?? []);
        if ((string) ($adminAction['type'] ?? '') === 'manual_refund') {
            throw new BusinessStateException('手动退款记录不能派发到上游通道', ['refund_no' => $refundNo]);
        }
        if (!in_array($status, [
            TradeConstant::REFUND_STATUS_CREATED,
            TradeConstant::REFUND_STATUS_FAILED,
        ], true)) {
            throw new BusinessStateException('当前退款状态不允许重新派发', [
                'refund_no' => $refundNo,
                'status' => $status,
            ]);
        }

        return $this->dispatchService->dispatch(
            $refundOrder,
            $status === TradeConstant::REFUND_STATUS_FAILED
        );
    }

    /**
     * 用原退款单主动核对渠道状态，并返回更新后的后台详情。
     *
     * 仅处理已派发的处理中通道退款；查询可能据可信结果推进成功或失败，
     * 不会再次调用上游退款申请接口，也不处理后台手动退款记录。
     *
     * @param string $refundNo 原退款单号
     * @return array<string, mixed> 更新后的退款详情
     * @throws ResourceNotFoundException 退款单不存在时抛出
     * @throws BusinessStateException 退款来源或当前状态不允许主动查渠道时抛出
     */
    public function queryRefundStatus(string $refundNo): array
    {
        $refundOrder = $this->queryService->findByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        if (($refundOrder->ext_json['admin_action']['type'] ?? '') === 'manual_refund') {
            throw new BusinessStateException('后台手动退款记录不查询上游通道', ['refund_no' => $refundNo]);
        }
        if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
            throw new BusinessStateException('只有处理中的退款单可以主动查询渠道状态', [
                'refund_no' => $refundNo,
                'status' => (int) $refundOrder->status,
            ]);
        }

        $this->dispatchService->queryStatus($refundOrder);

        return $this->queryService->detail($refundNo);
    }

    /**
     * 凭已核实的外部证据确认原退款单成功，并返回后台详情。
     *
     * @param string $refundNo 原退款单号
     * @param array{confirmed_amount:int,channel_refund_no:string,evidence:string,reason:string} $input 已校验的外部证据
     * @param int $adminId 当前管理员ID
     * @return array<string, mixed> 更新后的退款详情
     */
    public function confirmRefundSuccess(string $refundNo, array $input, int $adminId): array
    {
        $this->lifecycleService->confirmRefundSuccessByAdmin($refundNo, $input, $adminId);

        return $this->queryService->detail($refundNo);
    }

    /**
     * 取消未派发的原退款单，并返回后台详情。
     *
     * @param string $refundNo 原退款单号
     * @param string $reason 管理员取消原因
     * @param int $adminId 当前管理员ID
     * @return array<string, mixed> 更新后的退款详情
     */
    public function cancelRefund(string $refundNo, string $reason, int $adminId): array
    {
        $this->lifecycleService->cancelUnsentRefundByAdmin($refundNo, $reason, $adminId);

        return $this->queryService->detail($refundNo);
    }

    /**
     * 在当前事务中标记退款处理中或重试。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @param bool $isRetry 是否来自重试流程
     * @return RefundOrder 退款单模型
     */
    public function markRefundProcessingInCurrentTransaction(string $refundNo, array $input = [], bool $isRetry = false): RefundOrder
    {
        return $this->lifecycleService->markRefundProcessingInCurrentTransaction($refundNo, $input, $isRetry);
    }

    /**
     * 退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundSuccess($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundSuccessInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundSuccessInCurrentTransaction($refundNo, $input);
    }

    /**
     * 退款失败。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundFailed(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundFailed($refundNo, $input);
    }

    /**
     * 在当前事务中标记退款失败。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundFailedInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        return $this->lifecycleService->markRefundFailedInCurrentTransaction($refundNo, $input);
    }
}
