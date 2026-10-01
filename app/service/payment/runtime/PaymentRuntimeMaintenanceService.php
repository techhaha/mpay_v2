<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\common\constant\TradeConstant;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\config\PaymentTypeService;
use app\service\payment\order\PayOrderLifecycleService;
use app\service\payment\order\PayOrderRiskControlService;
use app\service\payment\order\PaymentPluginQueryResultValidator;
use app\service\payment\order\RefundDispatchService;
use app\service\payment\order\RefundLifecycleService;
use app\service\payment\settlement\SettlementAutomationService;
use app\service\payment\transfer\TransferService;
use support\Log;

/**
 * 支付运行时维护服务。
 *
 * 定时进程调用本服务完成通知重试、转账派发恢复、订单超时和主动查单。
 */
class PaymentRuntimeMaintenanceService extends BaseService
{
    /**
     * 主动查单轮转游标。
     *
     * payment-runtime 固定单进程运行，使用进程内游标避免长期处理中订单占满每批结果。
     */
    private string $activeQueryCursorRequestAt = '';

    private int $activeQueryCursorId = 0;

    /** 过期扫描独立轮转，查单失败或冻结的订单不能阻塞后续批次。 */
    private string $expiredCursorExpireAt = '';

    private int $expiredCursorId = 0;

    /**
     * 构造方法。
     *
     * @param MerchantNotifyDispatcherService $merchantNotifyDispatcherService 商户通知派发服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PaymentTypeService $paymentTypeService 支付方式服务
     * @param PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
     * @param NotifyService $notifyService 通知与日志服务
     * @param TransferService $transferService 转账服务
     * @param SettlementAutomationService $settlementAutomationService 清算自动化服务
     * @param PaymentQueueService $paymentQueueService 支付队列服务
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param RefundDispatchService $refundDispatchService 退款派发与查单服务
     * @param RefundLifecycleService $refundLifecycleService 退款生命周期服务
     * @param PaymentRecoveryTaskService $recoveryTaskService 支付恢复任务服务
     */
    public function __construct(
        protected MerchantNotifyDispatcherService $merchantNotifyDispatcherService,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService,
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentTypeService $paymentTypeService,
        protected PayOrderRiskControlService $payOrderRiskControlService,
        protected NotifyService $notifyService,
        protected TransferService $transferService,
        protected SettlementAutomationService $settlementAutomationService,
        protected PaymentQueueService $paymentQueueService,
        protected RefundOrderRepository $refundOrderRepository,
        protected RefundDispatchService $refundDispatchService,
        protected RefundLifecycleService $refundLifecycleService,
        protected PaymentRecoveryTaskService $recoveryTaskService
    ) {
    }

    /**
     * 重试可派发的商户通知。
     *
     * @param int $limit 批量数量
     * @return array<string, int> 执行摘要
     */
    public function retryMerchantNotifies(int $limit = 100): array
    {
        return [
            'dispatched' => $this->merchantNotifyDispatcherService->dispatchRetryableTasks($limit),
        ];
    }

    /**
     * 将已过期的非终态支付单推进为超时。
     *
     * @param int $limit 批量数量
     * @return array<string, int> 执行摘要
     */
    public function timeoutExpiredPayOrders(int $limit = 100): array
    {
        $summary = [
            'scanned' => 0,
            'timeout' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $orders = $this->payOrderRepository->listExpiredMutable(
            $this->now(), $limit, $this->expiredCursorExpireAt, $this->expiredCursorId
        );
        if ($orders->isEmpty() && $this->expiredCursorExpireAt !== '') {
            $this->expiredCursorExpireAt = '';
            $this->expiredCursorId = 0;
            $orders = $this->payOrderRepository->listExpiredMutable($this->now(), $limit);
        }

        foreach ($orders as $payOrder) {
            $this->expiredCursorExpireAt = (string) $payOrder->expire_at;
            $this->expiredCursorId = (int) $payOrder->id;
            $summary['scanned']++;

            try {
                $queryResult = $this->syncOnePayOrderByQuery($payOrder, 'runtime_timeout_final_query');
                if (($queryResult['reason_code'] ?? '') === 'RISK_FROZEN') {
                    $summary['skipped']++;
                    continue;
                }
                if (($queryResult['status'] ?? '') === 'error') {
                    // 最终查单本身失败时不能据此释放支付占用，保留原状态等待下次确认。
                    $summary['failed']++;
                    continue;
                }
                if ($queryResult['status'] === PaymentPluginStatusConstant::UNKNOWN) {
                    // 上游资金结果未确认，保留原状态和占用，由既有过期轮转继续核实。
                    $summary['skipped']++;
                    continue;
                }
                $latest = $this->payOrderRepository->findByPayNo((string) $payOrder->pay_no);
                if (!$latest || !in_array((int) $latest->status, TradeConstant::orderMutableStatuses(), true)) {
                    $summary['skipped']++;
                    continue;
                }
                $this->payOrderLifecycleService->timeoutPayOrder((string) $payOrder->pay_no, [
                    'reason' => '系统定时任务检测到支付单已过期',
                ]);
                $summary['timeout']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                Log::warning(sprintf(
                    '[PaymentRuntimeMaintenance] 支付单超时处理失败 pay_no=%s error=%s',
                    (string) $payOrder->pay_no,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * 主动查询支付中订单并按上游结果推进状态。
     *
     * @param int $limit 批量数量
     * @param int $minAgeSeconds 支付拉起后至少等待秒数
     * @return array<string, int> 执行摘要
     */
    public function syncPayingOrdersByQuery(int $limit = 50, int $minAgeSeconds = 60): array
    {
        $before = date('Y-m-d H:i:s', time() - max(1, $minAgeSeconds));
        $summary = [
            'scanned' => 0,
            'success' => 0,
            'failed' => 0,
            'closed' => 0,
            'pending' => 0,
            'unknown' => 0,
            'skipped' => 0,
            'error' => 0,
        ];

        $orders = $this->payOrderRepository->listPayingForActiveQuery(
            $before,
            $limit,
            $this->activeQueryCursorRequestAt,
            $this->activeQueryCursorId
        );
        if ($orders->isEmpty() && $this->activeQueryCursorRequestAt !== '') {
            $this->activeQueryCursorRequestAt = '';
            $this->activeQueryCursorId = 0;
            $orders = $this->payOrderRepository->listPayingForActiveQuery($before, $limit);
        }

        foreach ($orders as $payOrder) {
            $this->activeQueryCursorRequestAt = (string) $payOrder->request_at;
            $this->activeQueryCursorId = (int) $payOrder->id;
            $summary['scanned']++;
            $result = $this->syncOnePayOrderByQuery($payOrder, 'runtime_active_query');
            $status = (string) ($result['status'] ?? 'error');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            } else {
                $summary['error']++;
            }
        }

        return $summary;
    }

    /**
     * 恢复数据库已提交但队列消息未可靠送达的转账派发任务。
     *
     * @param int $limit 批量数量
     * @param int $minAgeSeconds 转账单至少等待秒数
     * @return array<string, int> 执行摘要
     */
    public function recoverPendingTransferDispatches(int $limit = 100, int $minAgeSeconds = 60): array
    {
        return $this->transferService->recoverPendingTransferDispatches($limit, $minAgeSeconds);
    }

    /**
     * 恢复转账主动查单任务。
     *
     * @param int $limit 批量数量
     * @param int $minAgeSeconds 转账单至少等待秒数
     * @return array<string, int> 执行摘要
     */
    public function recoverPendingTransferQueries(int $limit = 100, int $minAgeSeconds = 60): array
    {
        return $this->transferService->recoverPendingTransferQueries($limit, $minAgeSeconds);
    }

    /**
     * 补偿支付成功事务提交后可能丢失的商户通知任务和清算单。
     *
     * 两类下游记录都有稳定幂等键：通知使用 event_type/ref_no，清算使用由 pay_no
     * 派生的 settle_no。重复扫描只会复用既有记录，不会重复创建业务事实。
     *
     * @param int $limit 批量数量
     * @param int $minAgeSeconds 支付成功后至少等待秒数
     * @return array<string, int> 执行摘要
     */
    public function recoverSuccessfulPayOrderSideEffects(int $limit = 100, int $minAgeSeconds = 60): array
    {
        $summary = [
            'scanned' => 0,
            'recovered' => 0,
            'failed' => 0,
        ];
        foreach ($this->recoveryTaskService->listDueRefs(
            PaymentRecoveryTaskConstant::TYPE_PAY_SUCCESS_SIDE_EFFECT,
            max(1, $limit)
        ) as $payNo) {
            $task = $this->recoveryTaskService->claim(
                PaymentRecoveryTaskConstant::TYPE_PAY_SUCCESS_SIDE_EFFECT,
                $payNo
            );
            if (!$task) {
                continue;
            }
            $summary['scanned']++;
            try {
                $payOrder = $this->payOrderRepository->findByPayNo($payNo);
                if (!$payOrder || (int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
                    $this->recoveryTaskService->succeed($task);
                    $summary['recovered']++;
                    continue;
                }

                $notifyTask = $this->merchantNotifyDispatcherService->enqueuePaySuccess($payOrder);
                if ($notifyTask) {
                    $this->paymentQueueService->sendMerchantNotify((string) $notifyTask->notify_no);
                }

                $settlementOrder = $this->settlementAutomationService->createForPaidPayOrder($payOrder);
                if ($settlementOrder && $this->settlementAutomationService->shouldAutoComplete($settlementOrder)) {
                    if (!$this->paymentQueueService->sendSettlementComplete((string) $settlementOrder->settle_no)) {
                        throw new \RuntimeException('清算自动入账消息投递失败');
                    }
                }

                $this->recoveryTaskService->succeed($task);
                $summary['recovered']++;
            } catch (\Throwable $e) {
                $this->recoveryTaskService->retry($task, 60, $e->getMessage());
                $summary['failed']++;
                Log::warning(sprintf(
                    '[PaymentRuntimeMaintenance] 支付成功副作用补偿失败 pay_no=%s error=%s',
                    $payNo,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * 主动查询长时间处于处理中的退款单。
     *
     * @param int $limit 批量数量
     * @param int $minAgeSeconds 进入处理中后至少等待秒数
     * @return array<string, int> 执行摘要
     */
    public function syncProcessingRefundsByQuery(int $limit = 100, int $minAgeSeconds = 60): array
    {
        $summary = [
            'scanned' => 0,
            'success' => 0,
            'failed' => 0,
            'pending' => 0,
            'error' => 0,
        ];

        foreach ($this->recoveryTaskService->listDueRefs(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
            max(1, $limit)
        ) as $refundNo) {
            $task = $this->recoveryTaskService->claim(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
                $refundNo
            );
            if (!$task) {
                continue;
            }
            $summary['scanned']++;
            try {
                $refundOrder = $this->refundOrderRepository->findByRefundNo($refundNo);
                if (!$refundOrder || (int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
                    $this->recoveryTaskService->succeed($task);
                    $summary['pending']++;
                    continue;
                }
                $latest = $this->refundDispatchService->queryStatus($refundOrder);
                $status = (int) $latest->status;
                if ($status === TradeConstant::REFUND_STATUS_SUCCESS) {
                    $this->recoveryTaskService->succeed($task);
                    $summary['success']++;
                } elseif ($status === TradeConstant::REFUND_STATUS_FAILED) {
                    $this->recoveryTaskService->succeed($task);
                    $summary['failed']++;
                } else {
                    $this->recoveryTaskService->retry($task, 120, (string) $latest->last_error);
                    $summary['pending']++;
                }
            } catch (UnsupportedPaymentOperationException $e) {
                // 完成的是查询任务，不是退款；无查询能力时保留退款事实等待通知或人工核对。
                $this->recoveryTaskService->succeed($task);
                $summary['pending']++;
            } catch (\Throwable $e) {
                $this->recoveryTaskService->retry($task, 300, $e->getMessage());
                $summary['error']++;
                Log::warning(sprintf(
                    '[PaymentRuntimeMaintenance] 退款主动查单失败 refund_no=%s error=%s',
                    $refundNo,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * 追缴上游退款成功后尚未完成的本地余额冲减。
     *
     * @param int $limit 批量数量
     * @return array<string, int> 执行摘要
     */
    public function recoverRefundAccountReverses(int $limit = 100): array
    {
        $summary = [
            'scanned' => 0,
            'recovered' => 0,
            'pending' => 0,
            'failed' => 0,
        ];

        foreach ($this->recoveryTaskService->listDueRefs(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE,
            max(1, $limit)
        ) as $refundNo) {
            $task = $this->recoveryTaskService->claim(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE,
                $refundNo
            );
            if (!$task) {
                continue;
            }
            $summary['scanned']++;
            try {
                $result = $this->refundLifecycleService->recoverRefundAccountReverse($refundNo);
                if ($result['recovered']) {
                    $this->recoveryTaskService->succeed($task);
                } else {
                    $this->recoveryTaskService->retry($task, 300, '商户可用余额不足，等待后续资金冲减');
                }
                $summary[$result['recovered'] ? 'recovered' : 'pending']++;
            } catch (\Throwable $e) {
                $this->recoveryTaskService->retry($task, 300, $e->getMessage());
                $summary['failed']++;
                Log::critical(sprintf(
                    '[PaymentRuntimeMaintenance] 退款账务差额追缴失败 refund_no=%s error=%s',
                    $refundNo,
                    $e->getMessage()
                ));
            }
        }

        return $summary;
    }

    /**
     * 主动查询单笔支付单。
     *
     * 后台人工查单允许查询非支付中订单，用于处理本地失败/关闭/超时后上游实际成功的情况；
     * 查询结果仍然会通过支付单生命周期服务推进，避免绕开平台服务费和业务单同步逻辑。
     *
     * @param string $payNo 支付单号
     * @param string $source 查单来源
     * @return array<string, mixed> 查单结果
     */
    public function syncPayOrderByQuery(string $payNo, string $source = 'admin_manual_query'): array
    {
        $payNo = trim($payNo);
        $payOrder = $payNo !== '' ? $this->payOrderRepository->findByPayNo($payNo) : null;
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        return $this->syncOnePayOrderByQuery($payOrder, $source);
    }

    /**
     * 查询单笔支付单并按结果推进状态。
     *
     * @param PayOrder $payOrder 支付单
     * @param string $source 查单来源
     * @return array<string, mixed> 查单结果
     */
    private function syncOnePayOrderByQuery(PayOrder $payOrder, string $source = 'runtime_active_query'): array
    {
        $payNo = (string) $payOrder->pay_no;
        if ($this->payOrderRiskControlService->isFrozen($payOrder)) {
            return [
                'pay_no' => $payNo,
                'status' => 'skipped',
                'message' => '支付单已冻结，跳过主动查单',
                'reason_code' => 'RISK_FROZEN',
            ];
        }

        try {
            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $result = $plugin->query($this->buildQueryOrder($payOrder));
            $normalized = $this->validateQueryResult($payOrder, $result);
            $snapshot = $this->buildQuerySnapshot($normalized, $source);

            if ($normalized['status'] === PaymentPluginStatusConstant::SUCCESS) {
                $this->payOrderLifecycleService->markPaySuccess($payNo, [
                    'channel_order_no' => $normalized['channel_order_no'],
                    'channel_trade_no' => $normalized['channel_trade_no'],
                    'paid_at' => $normalized['paid_at'] ?: null,
                ]);
                $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_SUCCESS);

                return [
                    'pay_no' => $payNo,
                    'status' => 'success',
                    'snapshot' => $snapshot,
                ];
            }

            if ($normalized['status'] === PaymentPluginStatusConstant::CLOSED) {
                $this->payOrderLifecycleService->closePayOrder($payNo, [
                    'channel_order_no' => $normalized['channel_order_no'],
                    'channel_trade_no' => $normalized['channel_trade_no'],
                    'reason' => '主动查单返回渠道已关闭',
                ]);
                $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_SUCCESS);

                return [
                    'pay_no' => $payNo,
                    'status' => 'closed',
                    'snapshot' => $snapshot,
                ];
            }

            if ($normalized['status'] === PaymentPluginStatusConstant::FAILED) {
                $this->payOrderLifecycleService->markPayFailed($payNo, [
                    'channel_order_no' => $normalized['channel_order_no'],
                    'channel_trade_no' => $normalized['channel_trade_no'],
                    'channel_error_code' => $normalized['channel_error_code'],
                    'channel_error_msg' => $normalized['channel_error_msg'],
                    'failed_at' => $normalized['failed_at'] ?: null,
                ]);
                $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_FAILED);

                return [
                    'pay_no' => $payNo,
                    'status' => 'failed',
                    'snapshot' => $snapshot,
                ];
            }

            $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_PENDING);

            return [
                'pay_no' => $payNo,
                'status' => $normalized['status'],
                'snapshot' => $snapshot,
            ];
        } catch (UnsupportedPaymentOperationException $e) {
            $snapshot = $this->recordQueryError($payOrder, $e->getMessage(), 'QUERY_UNSUPPORTED', $source);
            $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_PENDING);

            return [
                'pay_no' => $payNo,
                'status' => 'skipped',
                'snapshot' => $snapshot,
                'reason_code' => 'QUERY_UNSUPPORTED',
            ];
        } catch (PaymentException $e) {
            $snapshot = $this->recordQueryError($payOrder, $e->getMessage(), (string) $e->getCode(), $source);
            $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_FAILED);
            return [
                'pay_no' => $payNo,
                'status' => 'error',
                'snapshot' => $snapshot,
                'reason_code' => (string) $e->getCode(),
            ];
        } catch (\Throwable $e) {
            $snapshot = $this->recordQueryError($payOrder, $e->getMessage(), 'QUERY_ERROR', $source);
            $this->recordActiveQueryLog($payOrder, $snapshot, NotifyConstant::PROCESS_STATUS_FAILED);
            return [
                'pay_no' => $payNo,
                'status' => 'error',
                'snapshot' => $snapshot,
                'reason_code' => 'QUERY_ERROR',
            ];
        }
    }

    /**
     * 构建插件查单参数。
     *
     * @param PayOrder $payOrder 支付单
     * @return array<string, mixed> 查单参数
     */
    private function buildQueryOrder(PayOrder $payOrder): array
    {
        return [
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'trace_no' => (string) $payOrder->trace_no,
            'chan_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'chan_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
            'pay_type_id' => (int) $payOrder->pay_type_id,
            'pay_type_code' => $this->paymentTypeService->resolveCodeById((int) $payOrder->pay_type_id),
            'amount' => (int) $payOrder->pay_amount,
            'pay_created_at' => (string) ($payOrder->created_at ?? ''),
            'client_ip' => (string) ($payOrder->client_ip ?? ''),
            '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
        ] + $this->paymentContext($payOrder);
    }

    /**
     * 读取下单时由插件明确返回的支付产品上下文。
     *
     * @param PayOrder $payOrder 支付单
     * @return array{pay_type_code:string,pay_product:string,pay_action:string,channel_context:array<string,mixed>}
     */
    private function paymentContext(PayOrder $payOrder): array
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        if ($context === []) {
            throw new PaymentException('支付单缺少插件支付上下文', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }
        $payType = trim((string) ($context['pay_type'] ?? ''));
        $payProduct = trim((string) ($context['pay_product'] ?? ''));
        $provisional = !empty($context['_provisional']);
        if ($payType === '' || ($payProduct === '' && !$provisional)) {
            throw new PaymentException('支付单插件支付上下文不完整', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        return [
            'pay_type_code' => $payType,
            'pay_product' => $payProduct,
            'pay_action' => trim((string) ($context['pay_action'] ?? '')),
            'channel_context' => (array) ($context['channel_context'] ?? []),
        ];
    }

    /**
     * 校验插件查单结果与当前支付单的一致性。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $result 插件查单结果
     * @return array<string, mixed> 生命周期输入
     */
    private function validateQueryResult(PayOrder $payOrder, array $result): array
    {
        $validated = PaymentPluginQueryResultValidator::make($result)
            ->withScene('query_result')
            ->withException(PaymentException::class)
            ->validate();
        if (!hash_equals((string) $payOrder->pay_no, (string) $validated['pay_no'])) {
            throw new PaymentException('插件查单返回的支付单号不匹配', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        $status = (string) $validated['status'];
        if ($status === PaymentPluginStatusConstant::SUCCESS
            && (int) $validated['paid_amount'] !== (int) $payOrder->pay_amount) {
            throw new PaymentException('插件查单返回的实付金额不匹配', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }
        if ($status === PaymentPluginStatusConstant::SUCCESS
            && trim((string) ($validated['chan_order_no'] ?? '')) === ''
            && trim((string) ($validated['chan_trade_no'] ?? '')) === '') {
            throw new PaymentException('插件查单成功结果缺少渠道流水号', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        foreach (['chan_order_no' => 'channel_order_no', 'chan_trade_no' => 'channel_trade_no'] as $field => $modelField) {
            $stored = trim((string) ($payOrder->{$modelField} ?? ''));
            $actual = trim((string) ($validated[$field] ?? ''));
            if ($stored !== '' && $actual !== '' && !hash_equals($stored, $actual)) {
                throw new PaymentException('插件查单返回的渠道流水与支付单不匹配', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                    'field' => $field,
                ]);
            }
        }

        return [
            'status' => $status,
            // 只传本次外部事实；空值由生命周期基于锁内最新订单保留，不能用旧快照补齐。
            'channel_order_no' => (string) ($validated['chan_order_no'] ?? ''),
            'channel_trade_no' => (string) ($validated['chan_trade_no'] ?? ''),
            'channel_status' => (string) ($validated['channel_status'] ?? ''),
            'channel_error_code' => (string) ($validated['channel_error_code'] ?? ''),
            'channel_error_msg' => (string) ($validated['channel_error_msg'] ?? $validated['message'] ?? ''),
            'message' => (string) ($validated['message'] ?? ''),
            'paid_at' => $validated['paid_at'] ?? null,
            'failed_at' => $validated['failed_at'] ?? null,
        ];
    }

    /**
     * 构建本次主动查单的返回快照。
     *
     * @param array<string, mixed> $normalized 归一化结果
     * @param string $source 查单来源
     * @return array<string, mixed> 快照
     */
    private function buildQuerySnapshot(array $normalized, string $source = 'runtime_active_query'): array
    {
        return [
            'queried_at' => $this->now(),
            'source' => $source,
            'status' => (string) $normalized['status'],
            'channel_status' => (string) ($normalized['channel_status'] ?? ''),
            'message' => (string) ($normalized['message'] ?? ''),
            'channel_order_no' => (string) ($normalized['channel_order_no'] ?? ''),
            'channel_trade_no' => (string) ($normalized['channel_trade_no'] ?? ''),
        ];
    }

    /**
     * 构建主动查单异常快照，异常不推进支付状态。
     * @param PayOrder $payOrder 支付单
     * @param string $message 错误信息
     * @param string $code 错误码
     * @param string $source 查单来源
     * @return array<string, mixed> 异常快照
     */
    private function recordQueryError(PayOrder $payOrder, string $message, string $code, string $source = 'runtime_active_query'): array
    {
        Log::warning(sprintf(
            '[PaymentRuntimeMaintenance] 主动查单失败 pay_no=%s code=%s error=%s',
            (string) $payOrder->pay_no,
            $code,
            $message
        ));

        $snapshot = [
            'queried_at' => $this->now(),
            'source' => $source,
            'status' => 'error',
            'channel_status' => '',
            'message' => $message,
            'error_code' => $code,
            'channel_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'channel_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
        ];
        return $snapshot;
    }

    /**
     * 记录主动查单快照到渠道查单日志。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $snapshot 查单快照
     * @param int $processStatus 处理状态
     * @return void
     */
    private function recordActiveQueryLog(PayOrder $payOrder, array $snapshot, int $processStatus): void
    {
        try {
            if ((int) $payOrder->channel_id <= 0 || trim((string) $payOrder->biz_no) === '') {
                return;
            }

            $this->notifyService->recordChannelNotify([
                'notify_no' => $this->generateNo('QRY'),
                'channel_id' => (int) $payOrder->channel_id,
                'notify_type' => NotifyConstant::NOTIFY_TYPE_QUERY,
                'biz_no' => (string) $payOrder->biz_no,
                'pay_no' => (string) $payOrder->pay_no,
                'channel_request_no' => (string) ($payOrder->channel_request_no ?? ''),
                'channel_trade_no' => (string) ($snapshot['channel_trade_no'] ?? $payOrder->channel_trade_no ?? ''),
                'raw_payload' => $snapshot,
                'verify_status' => NotifyConstant::VERIFY_STATUS_SUCCESS,
                'process_status' => $processStatus,
                'last_error' => $processStatus === NotifyConstant::PROCESS_STATUS_FAILED ? (string) ($snapshot['message'] ?? '') : '',
            ]);
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                '[PaymentRuntimeMaintenance] 记录主动查单日志失败 pay_no=%s error=%s',
                (string) $payOrder->pay_no,
                $e->getMessage()
            ));
        }
    }

}
