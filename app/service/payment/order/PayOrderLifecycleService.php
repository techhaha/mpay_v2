<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\NotifyConstant;
use app\common\constant\EventConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\PayOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use Webman\Event\Event;

/**
 * 支付单生命周期服务。
 *
 * 负责支付单状态推进、关闭、超时和手续费处理。
 *
 * @property PayOrderFeeService $payOrderFeeService 支付单手续费服务
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 */
class PayOrderLifecycleService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderFeeService $payOrderFeeService 支付单手续费服务
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
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
     * @param array $input 状态数据
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

        // 成功态优先使用插件回传的实际手续费，没有则沿用预估值。
        $actualFee = array_key_exists('fee_actual_amount', $input)
            ? (int) $input['fee_actual_amount']
            : (int) $payOrder->fee_estimated_amount;
        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

        // 成功后正式结算手续费，避免自有通道只冻结不扣减。
        $this->payOrderFeeService->settleSuccessFee($payOrder, $actualFee, $payNo, $traceNo);

        $payOrder->status = TradeConstant::ORDER_STATUS_SUCCESS;
        $payOrder->paid_at = $input['paid_at'] ?? $this->now();
        $payOrder->fee_actual_amount = $actualFee;
        // 平台代收和自有通道的手续费、结算状态规则不同，这里统一收口。
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
        $payOrder->ext_json = array_replace_recursive((array) $payOrder->ext_json, $input['ext_json'] ?? []);
        $payOrder->save();

        // 业务单状态也要一起收口，保证支付单和业务单一致。
        $this->syncBizOrderAfterSuccess($payOrder, $traceNo);
        $shouldNotifyMerchant = true;

        return $payOrder->refresh();
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
        // 失败时只释放需要冻结的手续费，避免重复扣减或重复释放。
        $this->payOrderFeeService->releaseFrozenFeeIfNeeded($payOrder, $payNo, $traceNo, '支付失败释放手续费');

        $payOrder->status = TradeConstant::ORDER_STATUS_FAILED;
        $payOrder->fee_status = (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_SELF
            ? TradeConstant::FEE_STATUS_RELEASED
            : TradeConstant::FEE_STATUS_NONE;
        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_NONE;
        $payOrder->callback_status = NotifyConstant::PROCESS_STATUS_FAILED;
        $payOrder->channel_trade_no = (string) ($input['channel_trade_no'] ?? $payOrder->channel_trade_no ?? '');
        $payOrder->channel_order_no = (string) ($input['channel_order_no'] ?? $payOrder->channel_order_no ?? '');
        $payOrder->channel_error_code = (string) ($input['channel_error_code'] ?? $payOrder->channel_error_code ?? '');
        $payOrder->channel_error_msg = (string) ($input['channel_error_msg'] ?? $payOrder->channel_error_msg ?? '支付失败');
        $payOrder->failed_at = $input['failed_at'] ?? $this->now();
        $payOrder->callback_times = (int) $payOrder->callback_times + 1;
        $payOrder->ext_json = array_replace_recursive((array) $payOrder->ext_json, $input['ext_json'] ?? []);
        $payOrder->save();

        // 支付单进入终态后，同步回业务单，避免上游只能依赖支付单判断结果。
        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_FAILED, 'failed_at');
        $shouldDispatchEvent = true;

        return $payOrder->refresh();
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
        // 关闭单据时同样要处理冻结手续费，防止资金一直占用。
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
            $extJson['lifecycle'] = array_replace((array) ($extJson['lifecycle'] ?? []), [
                'close_reason' => $reason,
            ]);
        }
        $payOrder->ext_json = array_replace_recursive($extJson, $input['ext_json'] ?? []);
        $payOrder->save();

        // 关闭态也要同步给业务单，避免后续继续拉起支付。
        $this->syncBizOrderAfterTerminalStatus($payOrder, $payNo, $traceNo, TradeConstant::ORDER_STATUS_CLOSED, 'closed_at');
        $shouldDispatchEvent = true;

        return $payOrder->refresh();
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
        // 超时单同样释放冻结手续费，确保后续可以重新发起支付。
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
            $extJson['lifecycle'] = array_replace((array) ($extJson['lifecycle'] ?? []), [
                'timeout_reason' => $reason,
            ]);
        }
        $payOrder->ext_json = array_replace_recursive($extJson, $input['ext_json'] ?? []);
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
     * @return void
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

}
