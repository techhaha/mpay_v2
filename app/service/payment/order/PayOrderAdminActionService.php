<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\TradeConstant;
use app\common\constant\RouteConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\repository\ops\log\PayOrderOperationLogRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\runtime\MerchantNotifyDispatcherService;
use app\service\payment\runtime\PaymentQueueService;
use app\service\payment\runtime\PaymentRuntimeMaintenanceService;

/**
 * 支付订单后台操作服务。
 *
 * 管理后台的高风险动作统一在这里收口，控制器只负责校验和转发参数；
 * 本服务会在执行前重新读取订单并做状态、金额、冻结等最终校验。
 */
class PayOrderAdminActionService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param BizOrderRepository $bizOrderRepository 业务单仓库
     * @param RefundService $refundService 退款服务
     * @param PaymentQueueService $paymentQueueService 支付队列服务
     * @param PaymentRuntimeMaintenanceService $runtimeMaintenanceService 支付运行维护服务
     * @param MerchantNotifyDispatcherService $merchantNotifyDispatcherService 商户通知派发服务
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
     * @param PayOrderFeeService $payOrderFeeService 自收服务费收取和待补扣处置
     * @param PayOrderRiskControlService $riskControlService 支付单风控服务
     * @param PayOrderActionResolverService $actionResolverService 支付单操作项服务
     * @param PayOrderOperationLogRepository $operationLogRepository 支付单后台操作日志仓库
     * @param RefundOrderRepository $refundOrderRepository 当前支付单退款金额查询
     * @return void
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundService $refundService,
        protected PaymentQueueService $paymentQueueService,
        protected PaymentRuntimeMaintenanceService $runtimeMaintenanceService,
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService,
        protected PayOrderLifecycleService $payOrderLifecycleService,
        protected PayOrderFeeService $payOrderFeeService,
        protected PayOrderRiskControlService $riskControlService,
        protected PayOrderActionResolverService $actionResolverService,
        protected PayOrderOperationLogRepository $operationLogRepository,
        protected RefundOrderRepository $refundOrderRepository
    ) {
    }

    /**
     * 查询单笔支付单的可操作项。
     *
     * @param string $payNo 支付单号
     * @return array<string, mixed> 操作项结构
     */
    public function actions(string $payNo): array
    {
        [$payOrder, $bizOrder] = $this->loadPayAndBizOrder($payNo);
        $row = $this->actionResolverService->resolveForPayOrder(
            $payOrder,
            $bizOrder,
            $this->refundOrderRepository->sumSuccessfulAmountByPayNo($payNo)
        );

        return [
            'pay_no' => (string) $payOrder->pay_no,
            'status' => (int) $payOrder->status,
            'actions' => $row['actions'],
            'enabled_actions' => $row['enabled_actions'],
            'freeze_info' => $row['freeze_info'],
            'is_frozen' => $row['is_frozen'],
            'refundable_amount' => $row['refundable_amount'],
            'refundable_amount_text' => $row['refundable_amount_text'],
        ];
    }

    /**
     * 全额补扣已成功自收订单的待收服务费。
     *
     * pay_no 和必填原因已由管理员入口验证。事务内锁订单，按原金额和固定幂等键扣款，
     * 同时保存费用状态、异常销项与审计日志；重复已扣请求不再移动资金或重复记操作。
     * 余额仍不足及任何写入故障回滚本次事务，不改变原支付成功事实，不发送新的支付事件。
     *
     * @param array{reason:string} $input 经校验的管理员操作参数，不接受自定补扣金额
     * @return array{pay_no:string,service_fee_status:int,service_fee_status_text:string,service_fee_amount:int}
     * @throws \app\exception\BalanceInsufficientException 可用余额仍不足，保持原成功和待补扣状态
     * @throws BusinessStateException 最新状态不允许补扣、订单风控冻结或账本不匹配
     * @throws ResourceNotFoundException 原支付单不存在
     */
    public function collectServiceFee(string $payNo, array $input, int $adminId): array
    {
        return $this->transactionRetry(function () use ($payNo, $input, $adminId): array {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$payOrder) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
            }
            $this->riskControlService->assertNotFrozen($payOrder, '补扣服务费');
            if ((int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS
                || (int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_SELF
                || (int) $payOrder->service_fee_amount <= 0
                || !in_array((int) $payOrder->service_fee_status, [
                    TradeConstant::SERVICE_FEE_STATUS_DUE, TradeConstant::SERVICE_FEE_STATUS_DEDUCTED,
                ], true)) {
                throw new BusinessStateException('只有已成功自收订单的待补扣服务费可以补扣', ['pay_no' => $payNo]);
            }
            $result = [
                'pay_no' => $payNo,
                'service_fee_status' => TradeConstant::SERVICE_FEE_STATUS_DEDUCTED,
                'service_fee_status_text' => TradeConstant::serviceFeeStatusMap()[TradeConstant::SERVICE_FEE_STATUS_DEDUCTED],
                'service_fee_amount' => (int) $payOrder->service_fee_amount,
            ];
            if ((int) $payOrder->service_fee_status === TradeConstant::SERVICE_FEE_STATUS_DEDUCTED) {
                return $result;
            }
            $this->payOrderFeeService->collectDueFeeInCurrentTransaction($payOrder, $adminId);
            $payOrder->service_fee_status = TradeConstant::SERVICE_FEE_STATUS_DEDUCTED;
            $payOrder->save();
            $this->recordOperation($payOrder, 'collect_service_fee', $adminId, trim($input['reason']),
                'success', '自收通道服务费已全额补扣', $result);
            return $result;
        });
    }

    /**
     * 重新投递支付成功商户通知。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 操作参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 操作结果
     */
    public function renotify(string $payNo, array $input = [], int $adminId = 0): array
    {
        [$payOrder, $bizOrder] = $this->loadPayAndBizOrder($payNo);
        $this->riskControlService->assertNotFrozen($payOrder, '重新通知');

        if ((int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('只有成功订单可以重新通知', [
                'pay_no' => $payNo,
                'status' => (int) $payOrder->status,
            ]);
        }

        $task = $this->merchantNotifyDispatcherService->enqueueManualPaySuccess(
            $payOrder,
            $bizOrder,
            $adminId,
            trim((string) ($input['reason'] ?? ''))
        );
        if (!$task) {
            throw new ValidationException('订单未配置 notify_url，无法重新通知');
        }

        $queued = $this->paymentQueueService->sendMerchantNotify((string) $task->notify_no);
        $result = [
            'pay_no' => (string) $payOrder->pay_no,
            'notify_no' => (string) $task->notify_no,
            'queued' => $queued,
            'status' => (int) $task->status,
        ];
        $this->recordOperation(
            $payOrder,
            'renotify',
            $adminId,
            trim((string) ($input['reason'] ?? '')),
            'success',
            $queued ? '商户通知任务已入队' : '商户通知任务已重置，等待定时补投',
            $result
        );

        return $result;
    }

    /**
     * 主动查询上游支付结果。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 操作参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 查单结果
     */
    public function activeQuery(string $payNo, array $input = [], int $adminId = 0): array
    {
        [$payOrder] = $this->loadPayAndBizOrder($payNo);
        $this->riskControlService->assertNotFrozen($payOrder, '主动查询');

        if ((int) $payOrder->status === TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('订单已成功，无需主动查询', ['pay_no' => $payNo]);
        }

        if ((int) $payOrder->channel_id <= 0) {
            throw new BusinessStateException('订单缺少通道信息，无法主动查询', ['pay_no' => $payNo]);
        }

        $result = $this->runtimeMaintenanceService->syncPayOrderByQuery($payNo, 'admin_manual_query');
        $this->recordOperation($payOrder, 'active_query', $adminId, trim((string) ($input['reason'] ?? '')), (string) ($result['status'] ?? 'unknown'), '主动查单完成', $result);

        return $result;
    }

    /**
     * 创建 API 退款单并投递上游退款队列。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 退款参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 操作结果
     */
    public function apiRefund(string $payNo, array $input = [], int $adminId = 0): array
    {
        [$payOrder] = $this->loadPayAndBizOrder($payNo);
        $this->riskControlService->assertNotFrozen($payOrder, 'API退款');

        if ((int) $payOrder->channel_id <= 0) {
            throw new BusinessStateException('订单缺少通道信息，无法发起 API 退款', ['pay_no' => $payNo]);
        }

        $refundOrder = $this->createRefundFromAdmin($payNo, array_replace($input, [
            'refund_full_remaining' => true,
            'reason' => trim((string) ($input['reason'] ?? '')) ?: '后台 API 全额退款',
        ]), $adminId, 'api_refund');

        $result = [
            'pay_no' => $payNo,
            'refund_no' => (string) $refundOrder->refund_no,
            'queued' => $this->paymentQueueService->sendRefundDispatch((string) $refundOrder->refund_no),
            'status' => (int) $refundOrder->status,
        ];
        $this->recordOperation($payOrder, 'api_refund', $adminId, trim((string) ($input['reason'] ?? '后台 API 全额退款')), 'success', 'API 退款单已创建', $result);

        return $result;
    }

    /**
     * 创建并直接标记手动退款成功。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 退款参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 操作结果
     */
    public function manualRefund(string $payNo, array $input = [], int $adminId = 0): array
    {
        [$payOrder] = $this->loadPayAndBizOrder($payNo);
        $this->riskControlService->assertNotFrozen($payOrder, '手动退款');

        $refundOrder = $this->createRefundFromAdmin($payNo, $input, $adminId, 'manual_refund');
        $refundOrder = $this->refundService->markRefundSuccess((string) $refundOrder->refund_no, [
            'succeeded_at' => $this->now(),
            'channel_refund_no' => '',
            'ext_json' => [
                'manual_refund' => [
                    'admin_id' => $adminId,
                    'reason' => trim((string) ($input['reason'] ?? '')),
                    'operated_at' => $this->now(),
                ],
            ],
        ]);

        $result = [
            'pay_no' => $payNo,
            'refund_no' => (string) $refundOrder->refund_no,
            'status' => (int) $refundOrder->status,
        ];
        $this->recordOperation($payOrder, 'manual_refund', $adminId, trim((string) ($input['reason'] ?? '')), 'success', '手动退款已登记成功', $result);

        return $result;
    }

    /**
     * 手动补正支付单为成功。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 补单参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 操作结果
     */
    public function manualSuccess(string $payNo, array $input = [], int $adminId = 0): array
    {
        [$payOrder] = $this->loadPayAndBizOrder($payNo);
        $this->riskControlService->assertNotFrozen($payOrder, '手动补单');

        if ((int) $payOrder->status === TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('订单已成功，无需手动补单', ['pay_no' => $payNo]);
        }

        $reason = $this->requireReason($input, '补单原因不能为空');

        $successInput = [
            'source' => 'admin_manual_success',
        ];

        $payOrder = $this->payOrderLifecycleService->markPaySuccess($payNo, $successInput);

        $result = [
            'pay_no' => (string) $payOrder->pay_no,
            'status' => (int) $payOrder->status,
            'paid_at' => $this->formatDateTime($payOrder->paid_at, ''),
        ];
        $this->recordOperation($payOrder, 'manual_success', $adminId, $reason, 'success', '支付单已手动补单为成功', $result);

        return $result;
    }

    /**
     * 冻结支付单。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 冻结参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 操作结果
     */
    public function freeze(string $payNo, array $input = [], int $adminId = 0): array
    {
        $payOrder = $this->riskControlService->freeze($payNo, $input, $adminId);
        $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);

        $result = $this->actionResolverService->resolveForPayOrder(
            $payOrder,
            $bizOrder,
            $this->refundOrderRepository->sumSuccessfulAmountByPayNo($payNo)
        );
        $this->recordOperation($payOrder, 'freeze', $adminId, trim((string) ($input['reason'] ?? '')), 'success', '订单已冻结', $result);

        return $result;
    }

    /**
     * 解冻支付单。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 解冻参数
     * @param int $adminId 管理员ID
     * @return array<string, mixed> 操作结果
     */
    public function unfreeze(string $payNo, array $input = [], int $adminId = 0): array
    {
        $payOrder = $this->riskControlService->unfreeze($payNo, $input, $adminId);
        $bizOrder = $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no);

        $result = $this->actionResolverService->resolveForPayOrder(
            $payOrder,
            $bizOrder,
            $this->refundOrderRepository->sumSuccessfulAmountByPayNo($payNo)
        );
        $this->recordOperation($payOrder, 'unfreeze', $adminId, trim((string) ($input['reason'] ?? '')), 'success', '订单已解冻', $result);

        return $result;
    }

    /**
     * 创建后台退款单。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 退款参数
     * @param int $adminId 管理员ID
     * @param string $type 操作类型
     * @return RefundOrder 退款单
     */
    private function createRefundFromAdmin(string $payNo, array $input, int $adminId, string $type): RefundOrder
    {
        $reason = $this->requireReason($input, '退款原因不能为空');
        $isFullRemainingRefund = (bool) ($input['refund_full_remaining'] ?? false);
        $refundAmount = $isFullRemainingRefund ? 0 : $this->parseAmount($input);
        $extJson = (array) ($input['ext_json'] ?? []);
        $extJson['admin_action'] = [
            'type' => $type,
            'admin_id' => $adminId,
            'reason' => $reason,
            'operated_at' => $this->now(),
        ];

        $refundInput = array_replace($input, [
            'pay_no' => $payNo,
            'reason' => $reason,
            'ext_json' => $extJson,
        ]);
        if (!$isFullRemainingRefund) {
            $refundInput['refund_amount'] = $refundAmount;
        }

        return $this->refundService->createRefund($refundInput);
    }

    /**
     * 加载支付单及业务单。
     *
     * @param string $payNo 支付单号
     * @return array{0: PayOrder, 1: BizOrder|null} 支付单和业务单
     */
    private function loadPayAndBizOrder(string $payNo): array
    {
        $payNo = trim($payNo);
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        return [
            $payOrder,
            $this->bizOrderRepository->findByBizNo((string) $payOrder->biz_no),
        ];
    }

    /**
     * 解析金额参数。
     *
     * @param array<string, mixed> $input 输入参数
     * @return int 金额，单位分
     */
    private function parseAmount(array $input): int
    {
        if (array_key_exists('refund_amount', $input) && (int) $input['refund_amount'] > 0) {
            return (int) $input['refund_amount'];
        }

        $money = trim((string) ($input['money'] ?? ''));
        if ($money === '') {
            throw new ValidationException('退款金额不能为空');
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $money)) {
            throw new ValidationException('金额格式不正确');
        }

        [$yuan, $cent] = array_pad(explode('.', $money, 2), 2, '0');
        $amount = ((int) $yuan * 100) + (int) str_pad($cent, 2, '0');
        if ($amount <= 0) {
            throw new ValidationException('退款金额不合法');
        }

        return $amount;
    }

    /**
     * 获取必填原因。
     *
     * @param array<string, mixed> $input 输入参数
     * @param string $message 错误提示
     * @return string 原因
     */
    private function requireReason(array $input, string $message): string
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException($message);
        }

        return $reason;
    }

    /**
     * 记录后台支付单操作日志。
     *
     * @param PayOrder $payOrder 支付单
     * @param string $action 操作码
     * @param int $adminId 管理员ID
     * @param string $reason 操作原因
     * @param string $resultStatus 结果状态
     * @param string $resultMessage 结果说明
     * @param array<string, mixed> $payload 结果摘要
     * @return void
     */
    private function recordOperation(PayOrder $payOrder, string $action, int $adminId, string $reason, string $resultStatus, string $resultMessage, array $payload = []): void
    {
        $this->operationLogRepository->create([
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'action' => $action,
            'admin_id' => $adminId,
            'reason' => $reason,
            'result_status' => $resultStatus,
            'result_message' => $resultMessage,
            'result_payload' => $payload,
            'created_at' => $this->now(),
        ]);
    }

}
