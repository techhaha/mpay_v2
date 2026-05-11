<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ConflictException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\BizOrder;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;

/**
 * 退款单创建服务。
 *
 * 负责退款单创建和幂等校验，不承载状态推进逻辑。
 *
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property BizOrderRepository $bizOrderRepository 业务单仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 * @property PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
 */
class RefundCreationService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
     * @return void
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected BizOrderRepository $bizOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected PayOrderRiskControlService $payOrderRiskControlService
    ) {
    }

    /**
     * 创建退款单。
     *
     * 当前支持整单或部分退款，同一支付单可创建多张退款单。
     *
     * @param array $input 退款参数
     * @return RefundOrder 退款单记录
     * @throws ValidationException
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     * @throws ConflictException
     */
    public function createRefund(array $input): RefundOrder
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        return $this->transactionRetry(function () use ($input, $payNo): RefundOrder {
            // 退款必须先锁定原支付单，确保状态和金额都满足退款前置条件。
            /** @var \app\model\payment\PayOrder|null $payOrder */
            $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
            if (!$payOrder) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
            }
            $this->payOrderRiskControlService->assertNotFrozen($payOrder, '退款');

            // 只有已支付订单才允许发起退款，其他状态直接拒绝。
            if ((int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
                throw new BusinessStateException('订单状态不允许退款', [
                    'pay_no' => $payNo,
                    'status' => (int) $payOrder->status,
                ]);
            }

            /** @var BizOrder|null $bizOrder */
            $bizOrder = $this->bizOrderRepository->findForUpdateByBizNo((string) $payOrder->biz_no);
            if (!$bizOrder) {
                throw new ResourceNotFoundException('业务单不存在', ['biz_no' => (string) $payOrder->biz_no]);
            }

            $isFullRemainingRefund = (bool) ($input['refund_full_remaining'] ?? false);
            $refundAmount = array_key_exists('refund_amount', $input)
                ? (int) $input['refund_amount']
                : ($isFullRemainingRefund ? 0 : (int) $payOrder->pay_amount);

            // 业务系统若传了商户退款单号，就优先按商户幂等键查重。
            $merchantRefundNo = trim((string) ($input['merchant_refund_no'] ?? ''));
            if ($merchantRefundNo !== '') {
                /** @var RefundOrder|null $existingByMerchantNo */
                $existingByMerchantNo = $this->refundOrderRepository->findByMerchantRefundNo((int) $payOrder->merchant_id, $merchantRefundNo);
                if ($existingByMerchantNo) {
                    if ((string) $existingByMerchantNo->pay_no !== $payNo || (int) $existingByMerchantNo->refund_amount !== $refundAmount) {
                        throw new ConflictException('幂等冲突', [
                            'refund_no' => (string) $existingByMerchantNo->refund_no,
                            'pay_no' => (string) $existingByMerchantNo->pay_no,
                            'merchant_refund_no' => $merchantRefundNo,
                        ]);
                    }

                    return $existingByMerchantNo;
                }
            }

            $reservedRefundAmount = 0;
            $reservedRefunds = $this->refundOrderRepository->listForUpdateByPayNoAndStatuses($payNo, [
                TradeConstant::REFUND_STATUS_CREATED,
                TradeConstant::REFUND_STATUS_PROCESSING,
                TradeConstant::REFUND_STATUS_SUCCESS,
            ], ['refund_amount']);
            foreach ($reservedRefunds as $reservedRefund) {
                $reservedRefundAmount += (int) $reservedRefund->refund_amount;
            }

            $remainingRefundable = max(0, (int) $payOrder->pay_amount - $reservedRefundAmount);
            if ($isFullRemainingRefund) {
                $refundAmount = $remainingRefundable;
            }

            if ($refundAmount <= 0) {
                throw new ValidationException('退款金额不合法');
            }

            if ($refundAmount > $remainingRefundable) {
                throw new BusinessStateException('退款金额超过可退余额', [
                    'pay_no' => $payNo,
                    'refund_amount' => $refundAmount,
                    'remaining' => $remainingRefundable,
                ]);
            }

            $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

            $feeReverseAmount = 0;
            if ((int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT && (int) $payOrder->pay_amount > 0) {
                $feeReverseAmount = (int) floor(((int) $payOrder->service_fee_amount) * $refundAmount / max(1, (int) $payOrder->pay_amount));
            }

            return $this->refundOrderRepository->create([
                'refund_no' => $this->generateNo('RFD'),
                'merchant_id' => (int) $payOrder->merchant_id,
                'merchant_group_id' => (int) $payOrder->merchant_group_id,
                'biz_no' => (string) $payOrder->biz_no,
                'trace_no' => $traceNo,
                'pay_no' => $payNo,
                'merchant_refund_no' => $merchantRefundNo !== '' ? $merchantRefundNo : $this->generateNo('MRF'),
                'channel_id' => (int) $payOrder->channel_id,
                'refund_amount' => $refundAmount,
                'fee_reverse_amount' => $feeReverseAmount,
                'status' => TradeConstant::REFUND_STATUS_CREATED,
                'channel_request_no' => $this->generateNo('RQR'),
                'reason' => (string) ($input['reason'] ?? ''),
                'request_at' => $this->now(),
                'processing_at' => null,
                'retry_count' => 0,
                'last_error' => '',
                'ext_json' => (array) ($input['ext_json'] ?? []),
            ]);
        });
    }
}
