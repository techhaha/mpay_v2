<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ConflictException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;

/**
 * 退款单创建服务。
 *
 * 负责退款单创建和幂等校验，不承载状态推进逻辑。
 */
class RefundCreationService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected PayOrderRepository $payOrderRepository,
        protected RefundOrderRepository $refundOrderRepository
    ) {
    }

    /**
     * 创建退款单。
     *
     * 当前仅支持整单全额退款，且同一支付单只能创建一张退款单。
     *
     * @param array $input 退款请求参数
     * @return RefundOrder
     */
    public function createRefund(array $input): RefundOrder
    {
        $payNo = trim((string) ($input['pay_no'] ?? ''));
        if ($payNo === '') {
            throw new ValidationException('pay_no 不能为空');
        }

        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        if ((int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new BusinessStateException('订单状态不允许退款', [
                'pay_no' => $payNo,
                'status' => (int) $payOrder->status,
            ]);
        }

        $refundAmount = array_key_exists('refund_amount', $input)
            ? (int) $input['refund_amount']
            : (int) $payOrder->pay_amount;

        if ($refundAmount !== (int) $payOrder->pay_amount) {
            throw new BusinessStateException('当前仅支持整单全额退款');
        }

        $merchantRefundNo = trim((string) ($input['merchant_refund_no'] ?? ''));
        if ($merchantRefundNo !== '') {
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

        if ($existingByPayNo = $this->refundOrderRepository->findByPayNo($payNo)) {
            if ($merchantRefundNo !== '' && (string) $existingByPayNo->merchant_refund_no !== $merchantRefundNo) {
                throw new ConflictException('重复退款', ['pay_no' => $payNo]);
            }

            return $existingByPayNo;
        }

        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

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
            'fee_reverse_amount' => (int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT ? (int) $payOrder->fee_actual_amount : 0,
            'status' => TradeConstant::REFUND_STATUS_CREATED,
            'channel_request_no' => $this->generateNo('RQR'),
            'reason' => (string) ($input['reason'] ?? ''),
            'request_at' => $this->now(),
            'processing_at' => null,
            'retry_count' => 0,
            'last_error' => '',
            'ext_json' => array_merge($input['ext_json'] ?? [], [
                'trace_no' => $traceNo,
            ]),
        ]);
    }
}
