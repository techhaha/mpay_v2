<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;
use app\service\account\funds\MerchantAccountService;

/**
 * 支付单平台服务费处理服务。
 *
 * 负责支付成功时的平台服务费结算，以及终态时的冻结服务费释放。
 *
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 */
class PayOrderFeeService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @return void
     */
    public function __construct(
        protected MerchantAccountService $merchantAccountService
    ) {
    }

    /**
     * 处理支付成功后的平台服务费结算。
     *
     * @param PayOrder $payOrder 支付订单
     * @param int $serviceFee 平台服务费
     * @param string $payNo 支付单号
     * @param string $traceNo 追踪号
     * @return void
     */
    public function settleSuccessFee(PayOrder $payOrder, int $serviceFee, string $payNo, string $traceNo): void
    {
        if ((int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_SELF) {
            return;
        }

        if ((int) $payOrder->service_fee_status === TradeConstant::SERVICE_FEE_STATUS_DEDUCTED) {
            return;
        }

        if ((int) $payOrder->service_fee_status === TradeConstant::SERVICE_FEE_STATUS_RELEASED) {
            if ($serviceFee > 0) {
                $this->merchantAccountService->debitPayFeeAmountInCurrentTransaction(
                    (int) $payOrder->merchant_id,
                    $serviceFee,
                    $payNo,
                    'PAY_LATE_DEDUCT:' . $payNo,
                    [
                        'pay_no' => $payNo,
                        'remark' => '自收通道终态后成功服务费补扣',
                    ],
                    $traceNo
                );
            }
            return;
        }

        if ($serviceFee <= 0) {
            return;
        }

        $this->merchantAccountService->deductFrozenAmountInCurrentTransaction(
            (int) $payOrder->merchant_id,
            $serviceFee,
            $payNo,
            'PAY_DEDUCT:' . $payNo,
            [
                'pay_no' => $payNo,
                'remark' => '自收通道服务费扣减',
            ],
            $traceNo
        );
    }

    /**
     * 释放支付单已冻结的平台服务费。
     *
     * @param PayOrder $payOrder 支付订单
     * @param string $payNo 支付单号
     * @param string $traceNo 追踪号
     * @param string $remark 备注
     * @return void
     */
    public function releaseFrozenFeeIfNeeded(PayOrder $payOrder, string $payNo, string $traceNo, string $remark): void
    {
        if ((int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_SELF) {
            return;
        }

        // 只有真正处于冻结态的服务费才需要释放，已经扣减或已释放的单子直接跳过。
        if ((int) $payOrder->service_fee_status !== TradeConstant::SERVICE_FEE_STATUS_FROZEN) {
            return;
        }

        $serviceFee = (int) $payOrder->service_fee_amount;
        if ($serviceFee <= 0) {
            return;
        }

        $this->merchantAccountService->releaseFrozenAmountInCurrentTransaction(
            (int) $payOrder->merchant_id,
            $serviceFee,
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
