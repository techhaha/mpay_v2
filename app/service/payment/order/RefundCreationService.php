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
 *
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 */
class RefundCreationService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @return void
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

        // 退款必须先锁定原支付单，确保状态和金额都满足退款前置条件。
        /** @var \app\model\payment\PayOrder|null $payOrder */
        $payOrder = $this->payOrderRepository->findByPayNo($payNo);
        if (!$payOrder) {
            throw new ResourceNotFoundException('支付单不存在', ['pay_no' => $payNo]);
        }

        // 只有已支付订单才允许发起退款，其他状态直接拒绝。
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

        // 业务系统若传了商户退款单号，就优先按商户幂等键查重。
        $merchantRefundNo = trim((string) ($input['merchant_refund_no'] ?? ''));
        if ($merchantRefundNo !== '') {
            // 商户退款单号是第一层幂等键，优先用它判断是否重复提交。
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

        // 没有商户退款单号时，用支付单号兜底，避免同一支付单重复创建退款单。
        /** @var RefundOrder|null $existingByPayNo */
        $existingByPayNo = $this->refundOrderRepository->findByPayNo($payNo);
        if ($existingByPayNo) {
            if ($merchantRefundNo !== '' && (string) $existingByPayNo->merchant_refund_no !== $merchantRefundNo) {
                throw new ConflictException('重复退款', ['pay_no' => $payNo]);
            }

            return $existingByPayNo;
        }

        $traceNo = (string) ($payOrder->trace_no ?: $payOrder->biz_no);

        // 退款单落库时同步追踪号、渠道单号和反向手续费，方便后续退款推进与对账。
        /** @var int $feeReverseAmount */
        $feeReverseAmount = ((int) $payOrder->channel_type === RouteConstant::CHANNEL_MODE_COLLECT)
            ? (int) $payOrder->fee_actual_amount
            : 0;
        // 代收场景下，退款需要把实际手续费作为反向金额记录下来，后续成功态才能正确冲正余额。
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
            'ext_json' => array_merge($input['ext_json'] ?? [], [
                'trace_no' => $traceNo,
            ]),
        ]);
    }
}
