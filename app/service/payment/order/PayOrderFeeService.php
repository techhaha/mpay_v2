<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BalanceInsufficientException;
use app\model\payment\PayOrder;
use app\service\account\funds\MerchantAccountService;
use app\service\payment\runtime\PaymentExceptionService;

/**
 * 支付单平台服务费处理服务。
 *
 * 在调用方事务中处理服务费扣款、待补扣异常和冻结释放，不自行推进支付终态。
 * 仅已释放费用补扣的余额不足允许形成欠费；账本、数据库及正常冻结错误继续抛出。
 *
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 */
class PayOrderFeeService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param PaymentExceptionService $paymentExceptionService 收费欠款异常登记与销项
     * @return void
     */
    public function __construct(
        protected MerchantAccountService $merchantAccountService,
        protected PaymentExceptionService $paymentExceptionService
    ) {
    }

    /**
     * 处理支付成功后的平台服务费结算。
     *
     * 调用方已锁支付单并持有成功事务。迟到成功的费用已释放时尝试全额补扣，
     * 余额不足在未发生扣款前登记欠费；其他错误不得吞掉。待补扣状态不自动再次收费。
     *
     * @param PayOrder $payOrder 支付订单
     * @param int $serviceFee 平台服务费，单位分
     * @param string $payNo 支付单号
     * @param string $traceNo 追踪号
     * @return int 实际费用状态，由生命周期与支付成功事实一并保存
     */
    public function settleSuccessFee(PayOrder $payOrder, int $serviceFee, string $payNo, string $traceNo): int
    {
        if ((int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_SELF || $serviceFee <= 0) {
            return TradeConstant::SERVICE_FEE_STATUS_NONE;
        }

        if (in_array((int) $payOrder->service_fee_status, [
            TradeConstant::SERVICE_FEE_STATUS_DEDUCTED,
            TradeConstant::SERVICE_FEE_STATUS_DUE,
        ], true)) {
            return (int) $payOrder->service_fee_status;
        }

        if ((int) $payOrder->service_fee_status === TradeConstant::SERVICE_FEE_STATUS_RELEASED) {
            try {
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
            } catch (BalanceInsufficientException $e) {
                // 账户扣款在余额检查前没有移动资金，只允许这一明确业务结果与支付事实分离。
                $this->paymentExceptionService->openPayFeeDueInCurrentTransaction($payOrder, $e);
                return TradeConstant::SERVICE_FEE_STATUS_DUE;
            }
            return TradeConstant::SERVICE_FEE_STATUS_DEDUCTED;
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

        return TradeConstant::SERVICE_FEE_STATUS_DEDUCTED;
    }

    /**
     * 在管理员补扣事务中收取原支付单待补扣服务费。
     *
     * 调用方已锁定成功的自收支付单并确认费用为 DUE。复用迟到补扣幂等键，
     * 余额仍不足或任何写入失败继续抛出，不部分扣款；调用方同事务保存费用状态和操作日志。
     *
     * @param PayOrder $payOrder 已在当前事务锁定的成功自收 DUE 支付单
     * @param int $adminId 当前真实管理员 ID，用于欠费销项
     * @throws BalanceInsufficientException 可用余额仍不足，调用方回滚整笔补扣
     */
    public function collectDueFeeInCurrentTransaction(PayOrder $payOrder, int $adminId): void
    {
        $payNo = (string) $payOrder->pay_no;
        $this->merchantAccountService->debitPayFeeAmountInCurrentTransaction(
            (int) $payOrder->merchant_id,
            (int) $payOrder->service_fee_amount,
            $payNo,
            'PAY_LATE_DEDUCT:' . $payNo,
            ['remark' => '管理员补扣自收通道服务费'],
            (string) ($payOrder->trace_no ?: $payOrder->biz_no)
        );
        $this->paymentExceptionService->resolvePayFeeDueInCurrentTransaction($payNo, $adminId);
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
