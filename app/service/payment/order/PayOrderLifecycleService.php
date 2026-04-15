<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;

/**
 * 支付单生命周期服务。
 *
 * 负责支付单状态推进、关闭、超时和手续费处理。
 */
class PayOrderLifecycleService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected PayOrderFeeService $payOrderFeeService,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository
    ) {
    }

    /**
     * 标记支付成功。
     *
     * 用于支付回调或主动查单成功后的状态推进；自有通道在这里完成手续费正式扣减。
     *
     * @param string $payNo 支付单号
     * @param array $input 回调或查单入参
     * @return PayOrder
     */
    public function markPaySuccess(string $payNo, array $input = []): PayOrder
    {
        return $this->transactionRetry(function () use ($payNo, $input) {
            return $this->markPaySuccessInCurrentTransaction($payNo, $input);
        });
    }

    /**
     * 在当前事务中标记支付成功。
     *
     * 该方法只处理状态推进和资金动作，不负责外部通道请求。
     *
     * @param string $payNo 支付单号
     * @param array $input 回调或查单入参
     * @return PayOrder
     */
    public function markPaySuccessInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $currentStatus = (int) $payOrder->status;
        if ($currentStatus === TradeConstant::ORDER_STATUS_SUCCESS) {
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

        $actualFee = array_key_exists('fee_actual_amount', $input)
            ? (int) $input['fee_actual_amount']
            : (int) $payOrder->fee_estimated_amount;
        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

        $this->payOrderFeeService->settleSuccessFee($payOrder, $actualFee, $payNo, $traceNo);

        $payOrder->status = TradeConstant::ORDER_STATUS_SUCCESS;
        $payOrder->paid_at = $input['paid_at'] ?? $this->now();
        $payOrder->fee_actual_amount = $actualFee;
        $payOrder->fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF
            ? TradeConstant::FEE_STATUS_DEDUCTED
            : TradeConstant::FEE_STATUS_NONE;
        $payOrder->settlement_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT
            ? TradeConstant::SETTLEMENT_STATUS_PENDING
            : TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->callback_status = NotifyConstant::PROCESS_STATUS_SUCCESS;
        $payOrder->channel_trade_no = (string) ($input['channel_trade_no'] ?? $payOrder->channel_trade_no ?? '');
        $payOrder->channel_order_no = (string) ($input['channel_order_no'] ?? $payOrder->channel_order_no ?? '');
        $payOrder->channel_error_code = '';
        $payOrder->channel_error_msg = '';
        $payOrder->callback_times = (int) $payOrder->callback_times + 1;
        $payOrder->ext_json = array_merge((array) $payOrder->ext_json, $input['ext_json'] ?? []);
        $payOrder->save();

        $this->syncBizOrderAfterSuccess($payOrder, $traceNo);

        return $payOrder->refresh();
    }

    /**
     * 标记支付失败。
     */
    public function markPayFailed(string $payNo, array $input = []): PayOrder
    {
        return $this->transactionRetry(function () use ($payNo, $input) {
            return $this->markPayFailedInCurrentTransaction($payNo, $input);
        });
    }

    /**
     * 在当前事务中标记支付失败。
     */
    public function markPayFailedInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $currentStatus = (int) $payOrder->status;
        if ($currentStatus === TradeConstant::ORDER_STATUS_FAILED) {
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
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付失败释放手续费');

        $payOrder->status = TradeConstant::ORDER_STATUS_FAILED;
        $payOrder->fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF
            ? TradeConstant::FEE_STATUS_RELEASED
            : TradeConstant::FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->callback_status = NotifyConstant::PROCESS_STATUS_FAILED;
        $payOrder->channel_error_code = (string) ($input['channel_error_code'] ?? $payOrder->channel_error_code ?? '');
        $payOrder->channel_error_msg = (string) ($input['channel_error_msg'] ?? $payOrder->channel_error_msg ?? '支付失败');
        $payOrder->failed_at = $input['failed_at'] ?? $this->now();
        $payOrder->callback_times = (int) $payOrder->callback_times + 1;
        $payOrder->ext_json = array_merge((array) $payOrder->ext_json, $input['ext_json'] ?? []);
        $payOrder->save();

        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_FAILED, 'failed_at');

        return $payOrder->refresh();
    }

    /**
     * 关闭支付单。
     */
    public function closePayOrder(string $payNo, array $input = []): PayOrder
    {
        return $this->transactionRetry(function () use ($payNo, $input) {
            return $this->closePayOrderInCurrentTransaction($payNo, $input);
        });
    }

    /**
     * 在当前事务中关闭支付单。
     */
    public function closePayOrderInCurrentTransaction(string $payNo, array $input = []): PayOrder
    {
        $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        $currentStatus = (int) $payOrder->status;
        if ($currentStatus === TradeConstant::ORDER_STATUS_CLOSED) {
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
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付关闭释放手续费');

        $payOrder->status = TradeConstant::ORDER_STATUS_CLOSED;
        $payOrder->fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF
            ? TradeConstant::FEE_STATUS_RELEASED
            : TradeConstant::FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->closed_at = $input['closed_at'] ?? $this->now();
        $extJson = (array) $payOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            $extJson['close_reason'] = $reason;
        }
        $payOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $payOrder->save();

        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_CLOSED, 'closed_at');

        return $payOrder->refresh();
    }

    /**
     * 标记支付超时。
     */
    public function timeoutPayOrder(string $payNo, array $input = []): PayOrder
    {
        return $this->transactionRetry(function () use ($payNo, $input) {
            return $this->timeoutPayOrderInCurrentTransaction($payNo, $input);
        });
    }

    /**
     * 在当前事务中标记支付超时。
     */
    public function timeoutPayOrderInCurrentTransaction(string $payNo, array $input = []): PayOrder
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
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付超时释放手续费');

        $payOrder->status = TradeConstant::ORDER_STATUS_TIMEOUT;
        $payOrder->fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF
            ? TradeConstant::FEE_STATUS_RELEASED
            : TradeConstant::FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->timeout_at = $input['timeout_at'] ?? $this->now();
        $extJson = (array) $payOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            $extJson['timeout_reason'] = $reason;
        }
        $payOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $payOrder->save();

        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_TIMEOUT, 'timeout_at');

        return $payOrder->refresh();
    }

    /**
     * 同步支付成功后的业务单状态。
     */
    private function syncBizOrderAfterSuccess(PayOrder $payOrder, string $traceNo): void
    {
        $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $payOrder->biz_no);
        if (!$bizOrder) {
            return;
        }

        $bizOrder->status = TradeConstant::ORDER_STATUS_SUCCESS;
        $bizOrder->paid_amount = (int) $bizOrder->paid_amount + (int) $payOrder->pay_amount;
        $bizOrder->active_pay_no = null;
        $bizOrder->paid_at = $payOrder->paid_at;
        if (empty($bizOrder->trace_no)) {
            $bizOrder->trace_no = $traceNo;
        }
        $bizOrder->save();
    }

    /**
     * 同步支付终态后的业务单状态。
     */
    private function syncBizOrderAfterTerminalStatus(PayOrder $payOrder, string $payNo, string $traceNo, int $status, string $timestampField): void
    {
        $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $payOrder->biz_no);
        if (!$bizOrder || (string) $bizOrder->active_pay_no !== $payNo) {
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

}
