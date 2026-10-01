<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\EventConstant;
use app\common\constant\PaymentRecoveryTaskConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\ops\log\PayOrderOperationLogRepository;
use app\service\payment\runtime\PaymentExceptionService;
use app\service\payment\runtime\PaymentRecoveryTaskService;
use support\Log;
use Webman\Event\Event;

/**
 * 支付单生命周期服务。
 *
 * 负责支付单状态推进、关闭、超时和平台服务费处理。
 *
 * @property PayOrderFeeService $payOrderFeeService 支付单平台服务费服务
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 */
class PayOrderLifecycleService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderFeeService $payOrderFeeService 支付单平台服务费服务
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param PayOrderOperationLogRepository $operationLogRepository 支付异常处置日志仓库
     * @param PaymentExceptionService $paymentExceptionService 支付业务异常服务
     * @param PaymentRecoveryTaskService $recoveryTaskService 支付恢复任务服务
     */
    public function __construct(
        protected PayOrderFeeService $payOrderFeeService,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderOperationLogRepository $operationLogRepository,
        protected PaymentExceptionService $paymentExceptionService,
        protected PaymentRecoveryTaskService $recoveryTaskService
    ) {
    }

    /**
     * 标记支付成功。
     *
     * 用于支付回调或主动查单成功后的状态推进；自收通道在这里完成平台服务费正式扣减。
     * 锁内先核对渠道事实，再判断状态；重复成功只补缺失事实，不重复扣费、累计金额或发事件。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 已校验的渠道引用、时间及可选 payment_context
     * @return PayOrder 支付订单模型
     */
    public function markPaySuccess(string $payNo, array $input = []): PayOrder
    {
        $shouldNotifyMerchant = false;

        $payOrder = $this->transactionRetry(function () use ($payNo, $input, &$shouldNotifyMerchant) {
            return $this->markPaySuccessInCurrentTransaction($payNo, $input, $shouldNotifyMerchant);
        });

        if ($shouldNotifyMerchant) {
            $this->dispatchPayOrderEvent(EventConstant::PAYMENT_PAY_ORDER_SUCCEEDED, $payOrder);
        }

        return $payOrder;
    }

    /**
     * 在当前事务中标记支付成功。
     *
     * 该方法只处理状态推进和资金动作，不负责外部通道请求。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markPaySuccessInCurrentTransaction(string $payNo, array $input = [], bool &$shouldNotifyMerchant = false): PayOrder
    {
        $shouldNotifyMerchant = false;
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        // 锁内先接纳渠道事实，再判断幂等；重复成功可以补流水，但不能重复处理资金。
        $this->applyChannelFacts($payOrder, $input);
        $currentStatus = (int) $payOrder->status;
        if ($currentStatus === TradeConstant::ORDER_STATUS_SUCCESS) {
            if ($payOrder->isDirty()) {
                $payOrder->save();
            }
            return $payOrder->refresh();
        }

        if (TradeConstant::isOrderTerminalStatus($currentStatus)) {
            return $this->markTerminalPaySuccessInCurrentTransaction($payOrder, $input, $currentStatus, $shouldNotifyMerchant);
        }

        if (!in_array($currentStatus, TradeConstant::orderMutableStatuses(), true)) {
            throw new BusinessStateException('支付单状态不允许当前操作', [
                'pay_no' => $payNo,
                'status' => $currentStatus,
            ]);
        }

        // 平台服务费按下单时的分账快照确定，第三方回调费用只作为上游成本，不参与商户扣费。
        $serviceFee = (int) $payOrder->service_fee_amount;
        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

        // 成功后正式结算平台服务费，避免自收通道只冻结不扣减。
        $feeStatus = $this->payOrderFeeService->settleSuccessFee($payOrder, $serviceFee, $payNo, $traceNo);

        $payOrder->status = TradeConstant::ORDER_STATUS_SUCCESS;
        $payOrder->paid_at = $input['paid_at'] ?? $this->now();
        // 平台代收和自收通道的服务费、清算状态规则不同，这里统一收口。
        $payOrder->service_fee_status = $feeStatus;
        $payOrder->settlement_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT
            ? TradeConstant::SETTLEMENT_STATUS_PENDING
            : TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->callback_status = NotifyConstant::PROCESS_STATUS_SUCCESS;
        $payOrder->channel_error_code = '';
        $payOrder->channel_error_msg = '';
        $payOrder->callback_times = (int) $payOrder->callback_times + 1;
        // 业务单状态也要一起收口，保证支付单和业务单一致。
        $shouldNotifyMerchant = $this->syncBizOrderAfterSuccess($payOrder, $traceNo);
        if ((int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT && !$shouldNotifyMerchant) {
            $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        }
        if ($shouldNotifyMerchant) {
            $this->markSuccessSideEffectsPending($payOrder);
        } else {
            $this->recordLateDuplicatePayment(
                $payOrder,
                $currentStatus,
                isset($input['callback_type']) ? 'PAY_CALLBACK' : 'SYSTEM'
            );
        }
        $payOrder->save();

        return $payOrder->refresh();
    }

    /**
     * 已进入失败、关闭或超时后的可信成功回调补偿。
     *
     * 这类状态不能静默忽略：如果业务单尚未被其它支付单支付成功，就补正为成功；
     * 如果业务单已经成功，则标记为重复晚到成功，留给后台人工处理或后续自动退款。
     *
     * @param PayOrder $payOrder 已加锁支付单
     * @param array<string, mixed> $input 状态数据
     * @param int $previousStatus 原终态
     * @param bool $shouldNotifyMerchant 是否需要通知商户
     * @return PayOrder 支付订单模型
     */
    private function markTerminalPaySuccessInCurrentTransaction(PayOrder $payOrder, array $input, int $previousStatus, bool &$shouldNotifyMerchant = false): PayOrder
    {
        $payNo = (string) $payOrder->pay_no;
        $serviceFee = (int) $payOrder->service_fee_amount;
        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

        $feeStatus = $this->payOrderFeeService->settleSuccessFee($payOrder, $serviceFee, $payNo, $traceNo);

        $payOrder->status = TradeConstant::ORDER_STATUS_SUCCESS;
        $payOrder->paid_at = $input['paid_at'] ?? $this->now();
        $payOrder->service_fee_status = $feeStatus;
        $payOrder->callback_status = NotifyConstant::PROCESS_STATUS_SUCCESS;
        $payOrder->channel_error_code = '';
        $payOrder->channel_error_msg = '';
        $payOrder->callback_times = (int) $payOrder->callback_times + 1;
        $shouldNotifyMerchant = $this->syncBizOrderAfterSuccess($payOrder, $traceNo);
        $payOrder->settlement_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT && $shouldNotifyMerchant
            ? TradeConstant::SETTLEMENT_STATUS_PENDING
            : TradeConstant::SETTLEMENT_STATUS_NONE;
        if ($shouldNotifyMerchant) {
            $this->markSuccessSideEffectsPending($payOrder);
        } else {
            $this->recordLateDuplicatePayment(
                $payOrder,
                $previousStatus,
                isset($input['callback_type']) ? 'PAY_CALLBACK' : 'SYSTEM'
            );
        }
        $payOrder->save();

        if (!$shouldNotifyMerchant) {
            Log::warning(sprintf(
                '[PayOrderLifecycle] 终态支付单收到晚到成功，业务单已支付 pay_no=%s biz_no=%s previous_status=%s',
                $payNo,
                (string) $payOrder->biz_no,
                $previousStatus
            ));
        }

        return $payOrder->refresh();
    }

    /**
     * 建立晚到重复支付的可检索异常处置记录。
     *
     * 这里只建立人工退款闭环，不擅自发起外部退款；原支付单仍保持真实成功状态，
     * 同时禁止正常商户成功通知和平台代收清算。
     *
     * @param PayOrder $payOrder 支付单
     * @param int $previousStatus 成功前状态
     * @param string $sourceType 异常发现来源
     * @return void
     */
    private function recordLateDuplicatePayment(PayOrder $payOrder, int $previousStatus, string $sourceType): void
    {
        $this->paymentExceptionService->openLateDuplicateInCurrentTransaction(
            $payOrder,
            $previousStatus,
            $sourceType
        );

        $this->operationLogRepository->create([
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'action' => 'late_duplicate_payment',
            'admin_id' => 0,
            'reason' => '业务单已由其他支付单完成，需对本次晚到成功执行退款处置',
            'result_status' => 'pending_refund',
            'result_message' => '已阻止商户成功通知和平台代收清算，等待退款闭环',
            'result_payload' => [
                'previous_status' => $previousStatus,
                'pay_amount' => (int) $payOrder->pay_amount,
                'channel_type' => (int) $payOrder->channel_type,
                'channel_id' => (int) $payOrder->channel_id,
                'channel_trade_no' => (string) $payOrder->channel_trade_no,
            ],
            'created_at' => $this->now(),
        ]);

        Log::critical(sprintf(
            '[PayOrderLifecycle] 检测到晚到重复支付，已进入退款处置队列 pay_no=%s biz_no=%s amount=%d previous_status=%d',
            (string) $payOrder->pay_no,
            (string) $payOrder->biz_no,
            (int) $payOrder->pay_amount,
            $previousStatus
        ));
    }

    /**
     * 在支付成功事务内建立通知/清算可靠恢复任务。
     *
     * @param PayOrder $payOrder 支付单
     * @return void
     */
    private function markSuccessSideEffectsPending(PayOrder $payOrder): void
    {
        $this->recoveryTaskService->scheduleInCurrentTransaction(
            PaymentRecoveryTaskConstant::TYPE_PAY_SUCCESS_SIDE_EFFECT,
            (string) $payOrder->pay_no,
            0,
            0,
            10,
            true
        );
    }

    /**
     * 标记支付失败。
     *
     * 锁内拒绝冲突流水，空引用保留原值；已终态只补缺失事实，不再次释放费用或回退状态。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function markPayFailed(string $payNo, array $input = []): PayOrder
    {
        $shouldDispatchEvent = false;

        $payOrder = $this->transactionRetry(function () use ($payNo, $input, &$shouldDispatchEvent) {
            return $this->markPayFailedInCurrentTransaction($payNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchPayOrderEvent(EventConstant::PAYMENT_PAY_ORDER_FAILED, $payOrder);
        }

        return $payOrder;
    }

    /**
     * 在当前事务中标记支付失败。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function markPayFailedInCurrentTransaction(string $payNo, array $input = [], bool &$shouldDispatchEvent = false): PayOrder
    {
        $shouldDispatchEvent = false;
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $this->applyChannelFacts($payOrder, $input);
        $currentStatus = (int) $payOrder->status;
        if (TradeConstant::isOrderTerminalStatus($currentStatus)) {
            if ($payOrder->isDirty()) {
                $payOrder->save();
            }
            return $payOrder->refresh();
        }

        if (!in_array($currentStatus, TradeConstant::orderMutableStatuses(), true)) {
            throw new BusinessStateException('支付单状态不允许当前操作', [
                'pay_no' => $payNo,
                'status' => $currentStatus,
            ]);
        }

        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);
        // 失败时只释放需要冻结的服务费，避免重复扣减或重复释放。
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付失败释放服务费');

        $rawChannelErrorCode = (string) ($input['channel_error_code'] ?? $payOrder->channel_error_code ?? '');
        $rawChannelErrorMsg = (string) ($input['channel_error_msg'] ?? $payOrder->channel_error_msg ?? '支付失败');
        $channelErrorCode = $this->clipColumnValue($rawChannelErrorCode, 64);
        $channelErrorMsg = $this->clipColumnValue($rawChannelErrorMsg, 255);
        $payOrder->status = TradeConstant::ORDER_STATUS_FAILED;
        $payOrder->service_fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF && (int) $payOrder->service_fee_amount > 0
            ? TradeConstant::SERVICE_FEE_STATUS_RELEASED
            : TradeConstant::SERVICE_FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->callback_status = NotifyConstant::PROCESS_STATUS_FAILED;
        $payOrder->channel_error_code = $channelErrorCode;
        $payOrder->channel_error_msg = $channelErrorMsg;
        $payOrder->failed_at = $input['failed_at'] ?? $this->now();
        $payOrder->callback_times = (int) $payOrder->callback_times + 1;
        $payOrder->save();

        // 支付单进入终态后，同步回业务单，避免上游只能依赖支付单判断结果。
        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_FAILED, 'failed_at');
        $shouldDispatchEvent = true;

        return $payOrder->refresh();
    }

    /**
     * 关闭支付单。
     *
     * 渠道关单入口已确认 closed；这里锁定最新订单核对引用。并发成功优先保留成功状态，
     * 不因迟到关单再次释放费用；本方法不发起第三方网络请求。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     */
    public function closePayOrder(string $payNo, array $input = []): PayOrder
    {
        $shouldDispatchEvent = false;

        $payOrder = $this->transactionRetry(function () use ($payNo, $input, &$shouldDispatchEvent) {
            return $this->closePayOrderInCurrentTransaction($payNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchPayOrderEvent(EventConstant::PAYMENT_PAY_ORDER_CLOSED, $payOrder);
        }

        return $payOrder;
    }

    /**
     * 在当前事务中关闭支付单。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function closePayOrderInCurrentTransaction(string $payNo, array $input = [], bool &$shouldDispatchEvent = false): PayOrder
    {
        $shouldDispatchEvent = false;
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $this->applyChannelFacts($payOrder, $input);
        $currentStatus = (int) $payOrder->status;
        if (TradeConstant::isOrderTerminalStatus($currentStatus)) {
            if ($payOrder->isDirty()) {
                $payOrder->save();
            }
            return $payOrder->refresh();
        }

        if (!in_array($currentStatus, TradeConstant::orderMutableStatuses(), true)) {
            throw new BusinessStateException('支付单状态不允许当前操作', [
                'pay_no' => $payNo,
                'status' => $currentStatus,
            ]);
        }

        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);
        // 关闭单据时同样要处理冻结服务费，防止资金一直占用。
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付关闭释放服务费');

        $payOrder->status = TradeConstant::ORDER_STATUS_CLOSED;
        $payOrder->service_fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF && (int) $payOrder->service_fee_amount > 0
            ? TradeConstant::SERVICE_FEE_STATUS_RELEASED
            : TradeConstant::SERVICE_FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->closed_at = $input['closed_at'] ?? $this->now();
        $payOrder->save();

        // 关闭态也要同步给业务单，避免后续继续拉起支付。
        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_CLOSED, 'closed_at');
        $shouldDispatchEvent = true;

        return $payOrder->refresh();
    }

    /**
     * 保存已校验的下单待支付事实。
     *
     * 事务内锁定最新订单并核对渠道事实；终态只允许补缺失事实，不回退状态、
     * 不重写 presentation，也不产生资金动作、通知或清算副作用。
     *
     * @param string $payNo 支付单号
     * @param array<string, mixed> $input 标准渠道引用及 payment_context/presentation 分区
     * @return PayOrder 最新支付单
     * @throws ResourceNotFoundException 支付单不存在
     * @throws BusinessStateException 渠道引用或支付产品与锁内事实冲突
     */
    public function recordPayProgress(string $payNo, array $input): PayOrder
    {
        return $this->transactionRetry(function () use ($payNo, $input) {
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$payOrder) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
            }

            $this->applyChannelFacts($payOrder, $input);
            if (!TradeConstant::isOrderTerminalStatus((int) $payOrder->status)) {
                $extJson = (array) $payOrder->ext_json;
                $extJson['presentation'] = $input['ext_json']['presentation'];
                $payOrder->ext_json = $extJson;
            }
            if ($payOrder->isDirty()) {
                $payOrder->save();
            }

            return $payOrder->refresh();
        });
    }

    /**
     * 接纳当前事务中已锁定支付单的可信渠道事实。
     *
     * 调用方已完成插件结果校验。空引用不擦除已存值，非空冲突在资金动作前拒绝；
     * 完整上下文升级暂存分区，确认分区只补缺失项。channel_context 整体保留或替换，
     * 不猜测内部字段。这里只修改模型，保存与状态、资金操作由调用方在同一事务完成。
     *
     * @param PayOrder $payOrder 当前事务中已加行锁的最新支付单
     * @param array<string, mixed> $input 标准渠道事实，非插件原始响应
     * @throws BusinessStateException 非空渠道引用、支付方式或产品冲突
     */
    private function applyChannelFacts(PayOrder $payOrder, array $input): void
    {
        $references = [];
        foreach (['channel_order_no', 'channel_trade_no'] as $field) {
            $stored = (string) $payOrder->$field;
            $actual = (string) ($input[$field] ?? '');
            if ($stored !== '' && $actual !== '' && !hash_equals($stored, $actual)) {
                throw new BusinessStateException('支付单渠道流水与已保存事实不一致', [
                    'pay_no' => (string) $payOrder->pay_no, 'field' => $field,
                ]);
            }
            $references[$field] = $actual !== '' ? $actual : $payOrder->$field;
        }

        $extJson = (array) $payOrder->ext_json;
        $inputExt = (array) ($input['ext_json'] ?? []);
        $incoming = (array) ($inputExt['payment_context'] ?? []);
        $existing = (array) ($extJson['payment_context'] ?? []);
        if ($incoming !== []) {
            foreach (['pay_type', 'pay_product'] as $field) {
                $stored = (string) ($existing[$field] ?? '');
                $actual = (string) $incoming[$field];
                if ($stored !== '' && $actual !== '' && !hash_equals($stored, $actual)) {
                    throw new BusinessStateException('支付单产品与已保存上下文不一致', [
                        'pay_no' => (string) $payOrder->pay_no, 'field' => $field,
                    ]);
                }
            }

            // 暂存态从完整标准分区升级；确认态只补空项，保留原数组避免重复写入。
            $confirmed = $existing !== [] && empty($existing['_provisional']);
            $context = $confirmed ? $existing : $incoming;
            foreach (['pay_type', 'pay_product', 'pay_action', 'channel_context'] as $field) {
                $stored = $existing[$field] ?? ($field === 'channel_context' ? [] : '');
                if ($stored !== '' && $stored !== []
                    && ($confirmed || $incoming[$field] === '' || $incoming[$field] === [])) {
                    $context[$field] = $stored;
                } else {
                    $context[$field] = $incoming[$field];
                }
            }
            $extJson['payment_context'] = $context;
        }

        // 展示由待支付入口单独按最新状态写入，两个不透明分区不能递归合并。
        unset($inputExt['payment_context'], $inputExt['presentation']);
        $payOrder->ext_json = array_replace_recursive($extJson, $inputExt);
        foreach ($references as $field => $value) {
            $payOrder->$field = $value;
        }
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
        $shouldDispatchEvent = false;

        $payOrder = $this->transactionRetry(function () use ($payNo, $input, &$shouldDispatchEvent) {
            return $this->timeoutPayOrderInCurrentTransaction($payNo, $input, $shouldDispatchEvent);
        });

        if ($shouldDispatchEvent) {
            $this->dispatchPayOrderEvent(EventConstant::PAYMENT_PAY_ORDER_TIMEOUT, $payOrder);
        }

        return $payOrder;
    }

    /**
     * 在当前事务中标记支付超时。
     *
     * @param string $payNo 支付单号
     * @param array $input 状态数据
     * @return PayOrder 支付订单模型
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function timeoutPayOrderInCurrentTransaction(string $payNo, array $input = [], bool &$shouldDispatchEvent = false): PayOrder
    {
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $currentStatus = (int) $payOrder->status;
        if ($currentStatus === TradeConstant::ORDER_STATUS_TIMEOUT) {
            return $payOrder;
        }

        if (TradeConstant::isOrderTerminalStatus($currentStatus)) {
            return $payOrder;
        }

        if (!in_array($currentStatus, TradeConstant::orderMutableStatuses(), true)) {
            throw new BusinessStateException('支付单状态不允许当前操作', [
                'pay_no' => $payNo,
                'status' => $currentStatus,
            ]);
        }

        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);
        // 超时单同样释放冻结服务费，确保后续可以重新发起支付。
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付超时释放服务费');

        $payOrder->status = TradeConstant::ORDER_STATUS_TIMEOUT;
        $payOrder->service_fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF && (int) $payOrder->service_fee_amount > 0
            ? TradeConstant::SERVICE_FEE_STATUS_RELEASED
            : TradeConstant::SERVICE_FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->timeout_at = $input['timeout_at'] ?? $this->now();
        $payOrder->ext_json = $this->keepSupportedExtJson(
            array_replace_recursive((array) $payOrder->ext_json, $input['ext_json'] ?? [])
        );
        $payOrder->save();

        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_TIMEOUT, 'timeout_at');
        $shouldDispatchEvent = true;

        return $payOrder->refresh();
    }

    /**
     * 同步支付成功后的业务单状态。
     *
     * @param PayOrder $payOrder 支付订单
     * @param string $traceNo 追踪号
     * @return bool 是否需要继续发送正常商户成功通知
     */
    private function syncBizOrderAfterSuccess(PayOrder $payOrder, string $traceNo): bool
    {
        $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $payOrder->biz_no);
        if (!$bizOrder) {
            return true;
        }

        $orderAmount = (int) $bizOrder->order_amount;
        $paidAmount = (int) $bizOrder->paid_amount;
        if ((int) $bizOrder->status === TradeConstant::ORDER_STATUS_SUCCESS && $orderAmount > 0 && $paidAmount >= $orderAmount) {
            return false;
        }

        $bizOrder->status = TradeConstant::ORDER_STATUS_SUCCESS;
        $bizOrder->paid_amount = $orderAmount > 0
            ? min($orderAmount, $paidAmount + (int) $payOrder->pay_amount)
            : $paidAmount + (int) $payOrder->pay_amount;
        $bizOrder->active_pay_no = null;
        $bizOrder->paid_at = $payOrder->paid_at;
        if (empty($bizOrder->trace_no)) {
            $bizOrder->trace_no = $traceNo;
        }
        $bizOrder->save();

        return true;
    }

    /**
     * 同步支付终态后的业务单状态。
     *
     * @param PayOrder $payOrder 支付订单
     * @param string $payNo 支付单号
     * @param string $traceNo 追踪号
     * @param int $status 状态
     * @param string $timestampField 时间字段名
     * @return void
     */
    private function syncBizOrderAfterTerminalStatus(PayOrder $payOrder, string $payNo, string $traceNo, int $status, string $timestampField): void
    {
        $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $payOrder->biz_no);
        if (!$bizOrder || (string) $bizOrder->active_pay_no !== $payNo) {
            // 只有当前生效的支付单才允许回写业务单，避免旧重试单覆盖新单状态。
            return;
        }

        $bizOrder->status = $status;
        $bizOrder->active_pay_no = null;
        $bizOrder->{$timestampField} = $payOrder->{$timestampField};
        if (empty($bizOrder->trace_no)) {
            $bizOrder->trace_no = $traceNo;
        }
        $bizOrder->save();
    }

    /**
     * 发送支付单事件。
     *
     * @param string $eventName 事件名称
     * @param PayOrder $payOrder 支付订单
     * @return void
     */
    private function dispatchPayOrderEvent(string $eventName, PayOrder $payOrder): void
    {
        Event::dispatch($eventName, [
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'pay_order' => $payOrder,
        ]);
    }

    /**
     * 按数据库短字段长度裁剪文本。
     *
     * @param string $value 原始文本
     * @param int $maxLength 最大字符长度
     * @return string 裁剪后的文本
     */
    private function clipColumnValue(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '' || $maxLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $maxLength
                ? mb_substr($value, 0, $maxLength, 'UTF-8')
                : $value;
        }

        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }

    /**
     * 支付单扩展字段只保留协议、商户透传、支付载体和承接页快照。
     *
     * @param array<string, mixed> $extJson 原始扩展字段
     * @return array<string, mixed> 已过滤扩展字段
     */
    private function keepSupportedExtJson(array $extJson): array
    {
        $supported = [];
        foreach (['_protocol_version', '_submit_type'] as $key) {
            if (array_key_exists($key, $extJson) && $extJson[$key] !== null && $extJson[$key] !== '') {
                $supported[$key] = $extJson[$key];
            }
        }

        foreach (['merchant', 'payment', 'presentation', 'payment_context', 'personal_receipt'] as $key) {
            if (isset($extJson[$key]) && is_array($extJson[$key]) && $extJson[$key] !== []) {
                $supported[$key] = $extJson[$key];
            }
        }

        return $supported;
    }

}
