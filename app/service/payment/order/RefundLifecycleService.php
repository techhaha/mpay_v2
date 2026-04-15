<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\account\funds\MerchantAccountService;

/**
 * 退款单生命周期服务。
 *
 * 负责退款单创建、处理中、成功、失败和重试等状态推进。
 */
class RefundLifecycleService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 标记退款处理中。
     */
    public function markRefundProcessing(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundProcessingInCurrentTransaction($refundNo, $input, false);
        });
    }

    /**
     * 退款重试。
     */
    public function retryRefund(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundProcessingInCurrentTransaction($refundNo, $input, true);
        });
    }

    /**
     * 在当前事务中标记退款处理中或重试。
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
        $refundOrder->last_error = (string) ($input['last_error'] ?? $refundOrder->last_error ?? '');
        if ($isRetry) {
            $refundOrder->retry_count = (int) $refundOrder->retry_count + 1;
            $refundOrder->channel_request_no = $this->generateNo('RQR');
        }

        $extJson = (array) $refundOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
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
     *
     * @param string $refundNo 退款单号
     * @param array $input 回调或查单入参
     * @return RefundOrder
     */
    public function markRefundSuccess(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundSuccessInCurrentTransaction($refundNo, $input);
        });
    }

    /**
     * 在当前事务中标记退款成功。
     *
     * @param string $refundNo 退款单号
     * @param array $input 回调或查单入参
     * @return RefundOrder
     */
    public function markRefundSuccessInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_SUCCESS) {
            return $refundOrder;
        }

        if ($currentStatus === TradeConstant::REFUND_STATUS_FAILED) {
            return $refundOrder;
        }

        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            return $refundOrder;
        }

        $payOrder = $this->payOrderRepository->findForUpdateByPayNo((string) $refundOrder->pay_no);
        if (!$payOrder || (int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('原支付单状态不允许退款', [
                'refund_no' => $refundNo,
                'pay_no' => (string) $refundOrder->pay_no,
            ]);
        }

        $traceNo = (string) ($refundOrder->trace_no ?: $refundOrder->biz_no);
        if ((int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT) {
            $reverseAmount = max(0, (int) $payOrder->pay_amount - (int) $payOrder->fee_actual_amount);
            if ((int) $payOrder->settlement_status === TradeConstant::SETTLEMENT_STATUS_SETTLED && $reverseAmount > 0) {
                $this->merchantAccountService->debitAvailableAmountInCurrentTransaction(
                    (int) $refundOrder->merchant_id,
                    $reverseAmount,
                    (string) $refundOrder->refund_no,
                    'REFUND_REVERSE:' . (string) $refundOrder->refund_no,
                    [
                        'pay_no' => (string) $refundOrder->pay_no,
                        'remark' => '平台代收退款冲减',
                    ],
                    $traceNo
                );
            }

            $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_REVERSED;
            $payOrder->save();
        }

        $refundOrder->status = TradeConstant::REFUND_STATUS_SUCCESS;
        $refundOrder->succeeded_at = $input['succeeded_at'] ?? $this->now();
        $refundOrder->channel_refund_no = (string) ($input['channel_refund_no'] ?? $refundOrder->channel_refund_no ?? '');
        $refundOrder->last_error = '';
        $refundOrder->ext_json = array_merge((array) $refundOrder->ext_json, $input['ext_json'] ?? []);
        $refundOrder->save();

        $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $refundOrder->biz_no);
        if ($bizOrder) {
            $bizOrder->refund_amount = (int) $bizOrder->order_amount;
            if (empty($bizOrder->trace_no)) {
                $bizOrder->trace_no = $traceNo;
            }
            $bizOrder->save();
        }

        return $refundOrder->refresh();
    }

    /**
     * 退款失败。
     */
    public function markRefundFailed(string $refundNo, array $input = []): RefundOrder
    {
        return $this->transactionRetry(function () use ($refundNo, $input) {
            return $this->markRefundFailedInCurrentTransaction($refundNo, $input);
        });
    }

    /**
     * 在当前事务中标记退款失败。
     */
    public function markRefundFailedInCurrentTransaction(string $refundNo, array $input = []): RefundOrder
    {
        $refundOrder = $this->refundOrderRepository->findForUpdateByRefundNo($refundNo);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refundNo]);
        }

        $currentStatus = (int) $refundOrder->status;
        if ($currentStatus === TradeConstant::REFUND_STATUS_FAILED) {
            return $refundOrder;
        }

        if (TradeConstant::isRefundTerminalStatus($currentStatus)) {
            return $refundOrder;
        }

        if ($currentStatus !== TradeConstant::REFUND_STATUS_CREATED && $currentStatus !== TradeConstant::REFUND_STATUS_PROCESSING) {
            throw new BusinessStateException('退款单状态不允许当前操作', [
                'refund_no' => $refundNo,
                'status' => $currentStatus,
            ]);
        }

        $refundOrder->status = TradeConstant::REFUND_STATUS_FAILED;
        $refundOrder->failed_at = $input['failed_at'] ?? $this->now();
        $refundOrder->channel_refund_no = (string) ($input['channel_refund_no'] ?? $refundOrder->channel_refund_no ?? '');
        $refundOrder->last_error = (string) ($input['last_error'] ?? $refundOrder->last_error ?? '');
        $extJson = (array) $refundOrder->ext_json;
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason !== '') {
            $extJson['fail_reason'] = $reason;
        }
        $refundOrder->ext_json = array_merge($extJson, $input['ext_json'] ?? []);
        $refundOrder->save();

        return $refundOrder->refresh();
    }
}
