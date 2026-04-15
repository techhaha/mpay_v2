<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;
use app\service\account\funds\MerchantAccountService;

/**
 * 支付单手续费处理服务。
 *
 * 负责支付成功时的手续费结算，以及终态时的冻结手续费释放。
 */
class PayOrderFeeService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 处理支付成功后的手续费结算。
     */
    public function settleSuccessFee(PayOrder $payOrder, int $actualFee, string $payNo, string $traceNo): void
    {
        if ((int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_SELF) {
            return;
        }

        $estimated = (int) $payOrder->fee_estimated_amount;
        if ($actualFee > $estimated) {
            if ($estimated > 0) {
                $this->merchantAccountService->deductFrozenAmountInCurrentTransaction(
                    (int) $payOrder->merchant_id,
                    $estimated,
                    $payNo,
                    'PAY_DEDUCT:' . $payNo,
                    [
                        'pay_no' => $payNo,
                        'remark' => '自有通道手续费扣减',
                    ],
                    $traceNo
                );
            }

            $diff = $actualFee - $estimated;
            if ($diff > 0) {
                $this->merchantAccountService->debitAvailableAmountInCurrentTransaction(
                    (int) $payOrder->merchant_id,
                    $diff,
                    $payNo,
                    'PAY_DEDUCT_DIFF:' . $payNo,
                    [
                        'pay_no' => $payNo,
                        'remark' => '自有通道手续费差额扣减',
                    ],
                    $traceNo
                );
            }
            return;
        }

        if ($actualFee < $estimated) {
            if ($actualFee > 0) {
                $this->merchantAccountService->deductFrozenAmountInCurrentTransaction(
                    (int) $payOrder->merchant_id,
                    $actualFee,
                    $payNo,
                    'PAY_DEDUCT:' . $payNo,
                    [
                        'pay_no' => $payNo,
                        'remark' => '自有通道手续费扣减',
                    ],
                    $traceNo
                );
            }

            $diff = $estimated - $actualFee;
            if ($diff > 0) {
                $this->merchantAccountService->releaseFrozenAmountInCurrentTransaction(
                    (int) $payOrder->merchant_id,
                    $diff,
                    $payNo,
                    'PAY_RELEASE:' . $payNo,
                    [
                        'pay_no' => $payNo,
                        'remark' => '自有通道手续费释放差额',
                    ],
                    $traceNo
                );
            }
            return;
        }

        if ($actualFee > 0) {
            $this->merchantAccountService->deductFrozenAmountInCurrentTransaction(
                (int) $payOrder->merchant_id,
                $actualFee,
                $payNo,
                'PAY_DEDUCT:' . $payNo,
                [
                    'pay_no' => $payNo,
                    'remark' => '自有通道手续费扣减',
                ],
                $traceNo
            );
        }
    }

    /**
     * 释放支付单已冻结的手续费。
     */
    public function releaseFrozenFeeIfNeeded(PayOrder $payOrder, string $payNo, string $traceNo, string $remark): void
    {
        if ((int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_SELF) {
            return;
        }

        if ((int) $payOrder->fee_status !== TradeConstant::FEE_STATUS_FROZEN) {
            return;
        }

        $this->merchantAccountService->releaseFrozenAmountInCurrentTransaction(
            (int) $payOrder->merchant_id,
            (int) $payOrder->fee_estimated_amount,
            $payNo,
            'PAY_RELEASE:' . $payNo,
            [
                'pay_no' => $payNo,
                'remark' => $remark,
            ],
            $traceNo
        );
    }
}
