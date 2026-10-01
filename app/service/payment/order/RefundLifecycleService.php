<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\EventConstant;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\UnauthorizedException;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\repository\ops\log\PayOrderOperationLogRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\payment\runtime\PaymentExceptionService;
use app\service\payment\runtime\PaymentRecoveryTaskService;
use support\Log;
use Webman\Event\Event;

/**
 * 退款单生命周期服务。
 *
 * 负责退款单处理中、成功、失败、取消及重试等状态推进。
 *
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 */
class RefundLifecycleService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param PayOrderOperationLogRepository $operationLogRepository 支付异常处置日志仓库
     * @param PaymentExceptionService $paymentExceptionService 支付业务异常服务
     * @param PaymentRecoveryTaskService $recoveryTaskService 支付恢复任务服务
     * @return void
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountService $merchantAccountService,
        protected PayOrderOperationLogRepository $operationLogRepository,
        protected PaymentExceptionService $paymentExceptionService,
        protected PaymentRecoveryTaskService $recoveryTaskService
    ) {
    }

    /**
     * 标记退款处理中。
     *
     * 由渠道受理后推进到处理中态，幂等地处理重复请求。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundProcessing(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundProcessingInCurrentTransaction($refundNo, $input, false);
        });
    }

    /**
     * 退款重试。
     *
     * 仅允许失败态退款单重新推进到处理中。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function retryRefund(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundProcessingInCurrentTransaction($refundNo, $input, true);
        });
    }

    /**
     * 原子取得一次退款派发权。
     *
     * @param string $refundNo 退款单号
     * @param bool $isRetry 是否为失败单重试
     * @param bool $supportsRefundQuery 原插件是否支持退款主动查询
     * @return array{refund_order: RefundOrder, claimed: bool}
     */
    public function claimRefundDispatch(string $refundNo, bool $isRetry = false, bool $supportsRefundQuery = false): array
    {
        return $this->transactionRetry(function () use ($refundNo, $isRetry, $supportsRefundQuery): array {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }

            $expectedStatus = $isRetry
                ? TradeConstant::REFUND_STATUS_FAILED
                : TradeConstant::REFUND_STATUS_CREATED;
            if ((int) $refundOrder->status !== $expectedStatus) {
                return ['refund_order' => $refundOrder, 'claimed' => false];
            }

            $refundOrder = $this->markRefundProcessingInCurrentTransaction(
                $refundNo,
                ['last_error' => ''],
                $isRetry
            );

            // 在请求上游前提交查询责任；任务写入失败必须与派发权一起回滚。
            if ($supportsRefundQuery) {
                $this->recoveryTaskService->scheduleInCurrentTransaction(
                    PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
                    $refundNo,
                    60,
                    0,
                    0,
                    $isRetry
                );
            }

            return ['refund_order' => $refundOrder, 'claimed' => true];
        });
    }

    /**
     * 记录取得派发权之前的失败，不改写其他调用者正在处理或已经结束的退款。
     *
     * @param string $refundNo 退款单号
     * @param string $reason 前置失败原因
     * @param bool $isRetry 是否重试失败单
     * @return RefundOrder 最新退款单
     */
    public function recordRefundDispatchError(string $refundNo, string $reason, bool $isRetry = false): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $reason, $isRetry): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            $expected = $isRetry ? TradeConstant::REFUND_STATUS_FAILED : TradeConstant::REFUND_STATUS_CREATED;
            if ((int) $refundOrder->status === $expected) {
                $refundOrder->last_error = mb_strcut($reason, 0, 255, 'UTF-8');
                $refundOrder->save();
            }

            return $refundOrder->refresh();
        });
    }

    /**
     * 释放尚未发送上游请求的派发权。
     *
     * @param string $refundNo 退款单号
     * @param string $reason 释放原因
     * @param int $restoreStatus 恢复状态
     * @return RefundOrder 最新退款单
     */
    public function releaseRefundDispatchClaim(
        string $refundNo,
        string $reason,
        int $restoreStatus = TradeConstant::REFUND_STATUS_CREATED
    ): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $reason, $restoreStatus): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
                return $refundOrder;
            }

            if (!in_array($restoreStatus, [TradeConstant::REFUND_STATUS_CREATED, TradeConstant::REFUND_STATUS_FAILED], true)) {
                throw new BusinessStateException('退款派发权恢复状态无效', ['status' => $restoreStatus]);
            }
            $refundOrder->status = $restoreStatus;
            $refundOrder->processing_at = null;
            $refundOrder->last_error = mb_strcut($reason, 0, 255, 'UTF-8');
            $refundOrder->save();
            $this->recoveryTaskService->completeInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
                $refundNo
            );

            return $refundOrder->refresh();
        });
    }

    /**
     * 保存退款处理过程中取得的渠道退款号。
     *
     * 在事务内锁定最新退款单，空值不擦除引用，非空冲突不因订单已成功而忽略。
     *
     * @param string $refundNo 退款单号
     * @param string $channelRefundNo 渠道退款号
     * @return RefundOrder 最新退款单
     * @throws BusinessStateException 非空渠道退款号与已存引用不一致
     */
    public function recordRefundProgress(string $refundNo, string $channelRefundNo = ''): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $channelRefundNo): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            $this->applyChannelRefundReference($refundOrder, $channelRefundNo);
            if ($refundOrder->isDirty()) {
                $refundOrder->save();
            }

            return $refundOrder->refresh();
        });
    }

    /**
     * 在当前事务中标记退款处理中或重试。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @param bool $isRetry 是否来自重试流程
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markRefundProcessingInCurrentTransaction(string $refundNo, array $input = [], bool $isRetry = false): RefundOrder
    {
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_PROCESSING) {
            return $refundOrder;
        }

        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            return $refundOrder;
        }

        if ($currentStatus !== TradeConstant::REFUND_STATUS_CREATED && $currentStatus !== TradeConstant::REFUND_STATUS_FAILED) {
            throw new BusinessStateException('退款单状态不允许当前操作', [
                'refund_no' => $refundNo,
                'status' => $currentStatus,
            ]);
        }

        // 退款失败后再重试时，只有失败态才允许重新推进到处理中。
        if ($currentStatus === TradeConstant::REFUND_STATUS_FAILED && !$isRetry) {
            return $refundOrder;
        }

        if ($isRetry && $currentStatus !== TradeConstant::REFUND_STATUS_FAILED) {
            return $refundOrder;
        }

        $refundOrder->status = TradeConstant::REFUND_STATUS_PROCESSING;
        $refundOrder->processing_at = $input['processing_at'] ?? $this->now();
        if (empty($refundOrder->request_at)) {
            $refundOrder->request_at = $input['request_at'] ?? $refundOrder->processing_at;
        }
        $refundOrder->last_error = mb_strcut(
            (string) ($input['last_error'] ?? $refundOrder->last_error ?? ''),
            0,
            255,
            'UTF-8'
        );
        if ($isRetry) {
            // 重试时生成新的渠道请求号，避免和上一轮失败请求混在一起。
            $refundOrder->retry_count = (int) $refundOrder->retry_count + 1;
            $refundOrder->channel_request_no = $this->generateNo('RQR');
        }

        $extJson = (array) $refundOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            // 把处理/重试原因单独保留到扩展字段里，便于后台排查。
            $extJson[$isRetry ? 'retry_reason' : 'processing_reason'] = $reason;
        }
        $refundOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $refundOrder->save();
        return $refundOrder->refresh();
    }

    /**
     * 退款成功。
     *
     * 成功后会推进退款单状态，并在平台代收场景下做余额冲减或结算逆向处理。
     * 渠道引用与状态、资金同事务接纳；重复成功只补缺失退款号，不重复冲正、累计或发事件。
     * 异常重复支付返还不累计正常业务退款，但仍发布退款成功事件供通道统计使用。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
    {
        $shouldDispatchEvent = false;

        $refundOrder = $this->transactionRetry(function () use ($refundNo, $input, &$shouldDispatchEvent) {
            return $this->markRefundSuccessInCurrentTransaction($refundNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchRefundOrderEvent(EventConstant::REFUND_ORDER_SUCCEEDED, $refundOrder);
        }

        return $refundOrder;
    }

    /**
     * 凭外部退款证据人工确认原退款单成功。
     *
     * 只接纳处理中的通道退款；资金冲正和操作证据在同一事务提交，成功事件在提交后发送。
     *
     * @param string $refundNo 原退款单号
     * @param array{confirmed_amount:int,channel_refund_no:string,evidence:string,reason:string} $input 已核实金额（分）和渠道证据
     * @param int $adminId 当前管理员ID
     * @return RefundOrder 已确认的原退款单
     * @throws UnauthorizedException 管理员身份缺失时抛出
     * @throws ResourceNotFoundException 原退款单不存在时抛出
     * @throws BusinessStateException 退款状态、来源或金额不符合人工确认条件时抛出
     */
    public function confirmRefundSuccessByAdmin(string $refundNo, array $input, int $adminId): RefundOrder
    {
        if ($adminId <= 0) {
            throw new UnauthorizedException('管理员未授权');
        }

        $shouldDispatchEvent = false;
        $refundOrder = $this->transactionRetry(function () use ($refundNo, $input, $adminId, &$shouldDispatchEvent): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING
                || ($refundOrder->ext_json['admin_action']['type'] ?? '') === 'manual_refund') {
                throw new BusinessStateException('只有处理中的通道退款可人工确认成功', ['refund_no' => $refundNo]);
            }
            $confirmedAmount = (int) $input['confirmed_amount'];
            if ((int) $refundOrder->refund_amount !== $confirmedAmount) {
                throw new BusinessStateException('核实金额与原退款单金额不一致', ['refund_no' => $refundNo]);
            }

            $refundOrder = $this->markRefundSuccessInCurrentTransaction(
                $refundNo,
                ['channel_refund_no' => $input['channel_refund_no']],
                $shouldDispatchEvent
            );
            $this->operationLogRepository->create([
                'pay_no' => (string) $refundOrder->pay_no,
                'biz_no' => (string) $refundOrder->biz_no,
                'action' => 'refund_manual_confirm',
                'admin_id' => $adminId,
                'reason' => $input['reason'],
                'result_status' => 'success',
                'result_message' => '原退款单已凭外部证据人工确认成功',
                'result_payload' => [
                    'refund_no' => $refundNo,
                    'confirmed_amount' => $confirmedAmount,
                    'channel_refund_no' => $input['channel_refund_no'],
                    'evidence' => $input['evidence'],
                ],
                'created_at' => $this->now(),
            ]);

            return $refundOrder;
        });

        if ($shouldDispatchEvent) {
            $this->dispatchRefundOrderEvent(EventConstant::REFUND_ORDER_SUCCEEDED, $refundOrder);
        }

        return $refundOrder;
    }

    /**
     * 取消尚未取得派发权的原退款单。
     *
     * 行锁使取消与渠道派发互斥；CLOSED 不再计入后续退款可退额度的占用。
     *
     * @param string $refundNo 原退款单号
     * @param string $reason 管理员取消原因
     * @param int $adminId 当前管理员ID
     * @return RefundOrder 已关闭的原退款单
     * @throws UnauthorizedException 管理员身份缺失时抛出
     * @throws ResourceNotFoundException 原退款单不存在时抛出
     * @throws BusinessStateException 退款已经派发或状态不允许取消时抛出
     */
    public function cancelUnsentRefundByAdmin(string $refundNo, string $reason, int $adminId): RefundOrder
    {
        if ($adminId <= 0) {
            throw new UnauthorizedException('管理员未授权');
        }

        return $this->transactionRetry(function () use ($refundNo, $reason, $adminId): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_CREATED
                || (string) ($refundOrder->channel_refund_no ?? '') !== '') {
                throw new BusinessStateException('只有尚未派发的退款单可以取消', ['refund_no' => $refundNo]);
            }

            $refundOrder->status = TradeConstant::REFUND_STATUS_CLOSED;
            $refundOrder->last_error = '';
            $refundOrder->save();
            $this->recoveryTaskService->completeInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
                $refundNo
            );
            $this->operationLogRepository->create([
                'pay_no' => (string) $refundOrder->pay_no,
                'biz_no' => (string) $refundOrder->biz_no,
                'action' => 'refund_cancel',
                'admin_id' => $adminId,
                'reason' => $reason,
                'result_status' => 'success',
                'result_message' => '未派发退款单已取消',
                'result_payload' => ['refund_no' => $refundNo, 'refund_amount' => (int) $refundOrder->refund_amount],
                'created_at' => $this->now(),
            ]);

            return $refundOrder->refresh();
        });
    }

    /**
     * 在当前事务中标记退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markRefundSuccessInCurrentTransaction(string $refundNo, array $input = [], bool &$shouldDispatchEvent = false): RefundOrder
    {
        $shouldDispatchEvent = false;
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        // 引用冲突必须先于幂等出口和资金冲正；重复成功仅可补缺失退款号。
        $this->applyChannelRefundReference($refundOrder, (string) ($input['channel_refund_no'] ?? ''));
        $currentStatus = (int) $refundOrder->status;
        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            if ($refundOrder->isDirty()) {
                $refundOrder->save();
            }
            return $refundOrder->refresh();
        }

        // 先锁定原支付单，避免退款推进时原单状态被并发修改。
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo((string) $refundOrder->pay_no);
        if (!$payOrder || (int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('原支付单状态不允许退款', [
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
            ]);
        }

        $traceNo = (string) ($refundOrder->trace_no ?: $refundOrder->biz_no);
        $accountReverseStatus = TradeConstant::REFUND_ACCOUNT_REVERSE_NOT_REQUIRED;
        $accountReverseRequiredAmount = 0;
        $accountReverseCollectedAmount = 0;
        $accountReverseDueAmount = 0;
        $accountReverseRecordedAt = null;
        $accountReverseRecoveredAt = null;
        if ((int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT) {
            if ((int) $payOrder->settlement_status === TradeConstant::SETTLEMENT_STATUS_SETTLED) {
                // 平台代收退款在已结算时，需要同步冲减商户可提现余额，口径按本次退款净额处理。
                $reverseAmount = max(0, (int) $refundOrder->refund_amount - (int) $refundOrder->fee_reverse_amount);
                if ($reverseAmount > 0) {
                    $accountReverseRequiredAmount = $reverseAmount;
                    $accountReverseCollectedAmount = $this->merchantAccountService->debitAvailableUpToInCurrentTransaction(
                        (int) $refundOrder->merchant_id,
                        $reverseAmount,
                        (string) $refundOrder->refund_no,
                        'REFUND_REVERSE_INITIAL:' . (string) $refundOrder->refund_no,
                        [
                            'pay_no' => (string) $refundOrder->pay_no,
                            'remark' => '上游退款成功后商户余额冲减',
                        ],
                        $traceNo
                    );
                    $accountReverseDueAmount = max(0, $reverseAmount - $accountReverseCollectedAmount);
                    $accountReverseRecordedAt = $this->now();
                    $accountReverseStatus = $accountReverseDueAmount > 0
                        ? TradeConstant::REFUND_ACCOUNT_REVERSE_DUE
                        : TradeConstant::REFUND_ACCOUNT_REVERSE_RECOVERED;
                    $accountReverseRecoveredAt = $accountReverseDueAmount === 0 ? $accountReverseRecordedAt : null;

                    if ($accountReverseDueAmount > 0) {
                        Log::critical(sprintf(
                            '[RefundLifecycle] 上游退款成功但本地余额冲减不足 refund_no=%s merchant_id=%d required=%d collected=%d due=%d',
                            (string) $refundOrder->refund_no,
                            (int) $refundOrder->merchant_id,
                            $reverseAmount,
                            $accountReverseCollectedAmount,
                            $accountReverseDueAmount
                        ));
                    }
                }
            }
        }

        // 真实退款成功照常落单和处理资金，不因异常返还而丢失渠道退款事实。
        $refundOrder->status = TradeConstant::REFUND_STATUS_SUCCESS;
        $refundOrder->succeeded_at = $input['succeeded_at'] ?? $this->now();
        $refundOrder->last_error = '';
        $refundOrder->account_reverse_status = $accountReverseStatus;
        $refundOrder->account_reverse_required_amount = $accountReverseRequiredAmount;
        $refundOrder->account_reverse_collected_amount = $accountReverseCollectedAmount;
        $refundOrder->account_reverse_due_amount = $accountReverseDueAmount;
        $refundOrder->account_reverse_recorded_at = $accountReverseRecordedAt;
        $refundOrder->account_reverse_recovered_at = $accountReverseRecoveredAt;
        $refundOrder->ext_json = array_merge((array) $refundOrder->ext_json, $input['ext_json'] ?? []);
        $refundOrder->save();
        $this->recoveryTaskService->completeInCurrentTransaction(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
            $refundNo
        );
        if ($accountReverseDueAmount > 0) {
            $this->recoveryTaskService->scheduleInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE,
                $refundNo,
                60
            );
        } else {
            $this->recoveryTaskService->completeInCurrentTransaction(
                PaymentRecoveryTaskConstant::TYPE_REFUND_ACCOUNT_REVERSE,
                $refundNo
            );
        }

        $isLateDuplicate = $this->paymentExceptionService->isLateDuplicatePayment((string) $payOrder->pay_no);
        // 重复收款返还是异常处置，不代表正常履约支付被退款。
        $bizOrder = $isLateDuplicate ? null
            : $this->bizOrderRepository->findForUpdateByBizNo((string) $refundOrder->biz_no);
        if ($bizOrder) {
            $bizOrder->refund_amount = (int) $bizOrder->refund_amount + (int) $refundOrder->refund_amount;
            if ((int) $bizOrder->refund_amount > (int) $bizOrder->order_amount) {
                Log::critical(sprintf(
                    '[RefundLifecycle] 实际累计退款超过订单金额 refund_no=%s pay_no=%s refund_amount=%d order_amount=%d',
                    (string) $refundOrder->refund_no,
                    (string) $refundOrder->pay_no,
                    (int) $bizOrder->refund_amount,
                    (int) $bizOrder->order_amount
                ));
            }
            if (empty($bizOrder->trace_no)) {
                $bizOrder->trace_no = $traceNo;
            }
            $bizOrder->save();
        }

        if ($isLateDuplicate) {
            $this->resolveLateDuplicateAfterRefund($payOrder, $refundOrder);
        }

        $shouldDispatchEvent = true;

        return $refundOrder->refresh();
    }

    /**
     * 当重复支付已足额退款时关闭异常处置记录。
     *
     * @param \app\model\payment\PayOrder $payOrder 原支付单
     * @param RefundOrder $refundOrder 本次退款单
     * @return void
     */
    private function resolveLateDuplicateAfterRefund(\app\model\payment\PayOrder $payOrder, RefundOrder $refundOrder): void
    {
        $refundedAmount = $this->refundOrderRepository->sumSuccessfulAmountByPayNo((string) $payOrder->pay_no);
        if ($refundedAmount < (int) $payOrder->pay_amount) {
            return;
        }

        if (!$this->paymentExceptionService->resolveLateDuplicateInCurrentTransaction(
            (string) $payOrder->pay_no,
            (string) $refundOrder->refund_no,
            $refundedAmount
        )) {
            return;
        }

        $this->operationLogRepository->create([
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'action' => 'late_duplicate_refunded',
            'admin_id' => 0,
            'reason' => '重复支付已足额退款',
            'result_status' => 'success',
            'result_message' => '重复支付异常处置已闭环',
            'result_payload' => [
                'refund_no' => (string) $refundOrder->refund_no,
                'refunded_amount' => $refundedAmount,
            ],
            'created_at' => $this->now(),
        ]);
    }

    /**
     * 追缴上游已退款但本地尚未完成的商户余额冲减。
     *
     * @param string $refundNo 退款单号
     * @return array{refund_order:RefundOrder,recovered:bool}
     */
    public function recoverRefundAccountReverse(string $refundNo): array
    {
        return $this->transactionRetry(function () use ($refundNo): array {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_SUCCESS) {
                return ['refund_order' => $refundOrder, 'recovered' => false];
            }

            $dueAmount = max(0, (int) $refundOrder->account_reverse_due_amount);
            if ((int) $refundOrder->account_reverse_status !== TradeConstant::REFUND_ACCOUNT_REVERSE_DUE || $dueAmount <= 0) {
                return ['refund_order' => $refundOrder, 'recovered' => true];
            }

            $traceNo = (string) ($refundOrder->trace_no ?: $refundOrder->biz_no);
            $collectedBefore = max(0, (int) $refundOrder->account_reverse_collected_amount);
            $collectedNow = $this->merchantAccountService->debitAvailableUpToInCurrentTransaction(
                (int) $refundOrder->merchant_id,
                $dueAmount,
                (string) $refundOrder->refund_no,
                'REFUND_REVERSE_RECOVERY:' . (string) $refundOrder->refund_no . ':' . $collectedBefore,
                [
                    'pay_no' => (string) $refundOrder->pay_no,
                    'remark' => '退款成功账务差额追缴',
                ],
                $traceNo
            );
            $remainingDue = max(0, $dueAmount - $collectedNow);
            $refundOrder->account_reverse_collected_amount = $collectedBefore + $collectedNow;
            $refundOrder->account_reverse_due_amount = $remainingDue;
            if ($remainingDue === 0) {
                $refundOrder->account_reverse_status = TradeConstant::REFUND_ACCOUNT_REVERSE_RECOVERED;
                $refundOrder->account_reverse_recovered_at = $this->now();
            }
            $refundOrder->save();

            return ['refund_order' => $refundOrder->refresh(), 'recovered' => $remainingDue === 0];
        });
    }

    /**
     * 记录退款主动查单调度结果。
     *
     * @param string $refundNo 退款单号
     * @param string $result 查询结果摘要
     * @param string $error 错误摘要
     * @return RefundOrder 最新退款单
     */
    public function recordRefundQueryAttempt(
        string $refundNo,
        string $result,
        string $error = ''
    ): RefundOrder {
        return $this->transactionRetry(function () use ($refundNo, $result, $error): RefundOrder {
            $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
            if (!$refundOrder) {
                throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
            }
            if ((int) $refundOrder->status !== TradeConstant::REFUND_STATUS_PROCESSING) {
                return $refundOrder;
            }

            $summary = $error !== '' ? $error : ($result === 'pending' ? '渠道退款仍在处理中' : '');
            $refundOrder->last_error = mb_strcut($summary, 0, 255, 'UTF-8');
            $refundOrder->save();

            return $refundOrder->refresh();
        });
    }

    /**
     * 退款失败。
     *
     * 渠道引用在锁内先核对；已终态保持原状态，仅补可信缺失引用，不重复完成资金动作。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     */
    public function markRefundFailed(string $refundNo, array $input = []): RefundOrder
    {
        $shouldDispatchEvent = false;

        $refundOrder = $this->transactionRetry(function () use ($refundNo, $input, &$shouldDispatchEvent) {
            return $this->markRefundFailedInCurrentTransaction($refundNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchRefundOrderEvent(EventConstant::REFUND_ORDER_FAILED, $refundOrder);
        }

        return $refundOrder;
    }

    /**
     * 在当前事务中标记退款失败。
     *
     * @param string $refundNo 退款单号
     * @param array $input 输入参数
     * @return RefundOrder 退款单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markRefundFailedInCurrentTransaction(string $refundNo, array $input = [], bool &$shouldDispatchEvent = false): RefundOrder
    {
        $shouldDispatchEvent = false;
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $this->applyChannelRefundReference($refundOrder, (string) ($input['channel_refund_no'] ?? ''));
        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_FAILED || TradeConstant::isRefundTerminalStatus($currentStatus)) {
            if ($refundOrder->isDirty()) {
                $refundOrder->save();
            }
            return $refundOrder->refresh();
        }

        if ($currentStatus !== TradeConstant::REFUND_STATUS_CREATED && $currentStatus !== TradeConstant::REFUND_STATUS_PROCESSING) {
            throw new BusinessStateException('退款单状态不允许当前操作', [
                'refund_no' => $refundNo,
                'status' => $currentStatus,
            ]);
        }

        // 失败状态只更新失败信息，不再改动原支付单和业务单。
        $refundOrder->status = TradeConstant::REFUND_STATUS_FAILED;
        $refundOrder->failed_at = $input['failed_at'] ?? $this->now();
        $refundOrder->last_error = mb_strcut(
            (string) ($input['last_error'] ?? $refundOrder->last_error ?? ''),
            0,
            255,
            'UTF-8'
        );
        $extJson = (array) $refundOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            // 失败原因也放进扩展字段，方便后台对比渠道返回和内部处理结果。
            $extJson['fail_reason'] = $reason;
        }
        $refundOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $refundOrder->save();
        $this->recoveryTaskService->completeInCurrentTransaction(
            PaymentRecoveryTaskConstant::TYPE_REFUND_ACTIVE_QUERY,
            $refundNo
        );
        $shouldDispatchEvent = true;

        return $refundOrder->refresh();
    }

    /**
     * 接纳当前事务中已锁定退款单的可信渠道退款号。
     *
     * 空值不擦除流水，非空冲突在幂等判断及账户冲正前拒绝。这里只修改模型；
     * 进度、成功和失败调用方负责在各自的同一事务内保存，不能提前单独提交退款号。
     *
     * @param RefundOrder $refundOrder 当前事务中已加行锁的最新退款单
     * @param string $channelRefundNo 已校验插件结果中的渠道退款号
     * @throws BusinessStateException 两个非空渠道退款号不一致
     */
    private function applyChannelRefundReference(RefundOrder $refundOrder, string $channelRefundNo): void
    {
        $stored = (string) $refundOrder->channel_refund_no;
        if ($stored !== '' && $channelRefundNo !== '' && !hash_equals($stored, $channelRefundNo)) {
            throw new BusinessStateException('退款单渠道流水与已保存事实不一致', [
                'refund_no' => (string) $refundOrder->refund_no, 'field' => 'channel_refund_no',
            ]);
        }
        if ($channelRefundNo !== '') {
            $refundOrder->channel_refund_no = $channelRefundNo;
        }
    }

    /**
     * 发送退款单事件。
     *
     * @param string $eventName 事件名称
     * @param RefundOrder $refundOrder 退款单
     * @return void
     */
    private function dispatchRefundOrderEvent(string $eventName, RefundOrder $refundOrder): void
    {
        Event::dispatch($eventName, [
            'refund_no' => (string) $refundOrder->refund_no,
            'pay_no' => (string) $refundOrder->pay_no,
            'biz_no' => (string) $refundOrder->biz_no,
            'refund_order' => $refundOrder,
        ]);
    }

}
