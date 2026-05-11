<?php

namespace app\service\payment\settlement;

use app\common\base\BaseService;
use app\common\constant\EventConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ResourceNotFoundException;
use app\exception\ValidationException;
use app\model\payment\SettlementOrder;
use app\repository\payment\settlement\SettlementItemRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\payment\order\PayOrderRiskControlService;
use Webman\Event\Event;

/**
 * 清算生命周期服务。
 *
 * 负责清算单创建、明细写入、入账完成和失败终态处理。
 *
 * @property SettlementOrderRepository $settlementOrderRepository 结算订单仓库
 * @property SettlementItemRepository $settlementItemRepository 结算明细仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property RefundOrderRepository $refundOrderRepository 退款单仓库
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 * @property PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
 */
class SettlementLifecycleService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param SettlementOrderRepository $settlementOrderRepository 结算订单仓库
     * @param SettlementItemRepository $settlementItemRepository 结算明细仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param RefundOrderRepository $refundOrderRepository 退款订单仓库
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
     * @return void
     */
    public function __construct(
        protected SettlementOrderRepository $settlementOrderRepository,
        protected SettlementItemRepository $settlementItemRepository,
        protected PayOrderRepository $payOrderRepository,
        protected RefundOrderRepository $refundOrderRepository,
        protected MerchantAccountService $merchantAccountService,
        protected PayOrderRiskControlService $payOrderRiskControlService
    ) {
    }

    /**
     * 创建清算单和明细。
     *
     * 适用于平台代收链路的清算批次生成，会同时写入汇总与明细。
     *
     * @param array $input 清算参数
     * @param array $items 清算明细
     * @return SettlementOrder 清算单记录
     * @throws ValidationException
     */
    public function createSettlementOrder(array $input, array $items = []): SettlementOrder
    {
        $settleNo = trim((string) ($input['settle_no'] ?? ''));
        if ($settleNo === '') {
            $settleNo = $this->generateNo('STL');
        }

        // 清算单号天然幂等，同一批次重复触发时直接复用已有记录。
        if ($existing = $this->settlementOrderRepository->findBySettleNo($settleNo)) {
            return $existing;
        }

        $merchantId = (int) ($input['merchant_id'] ?? 0);
        $merchantGroupId = (int) ($input['merchant_group_id'] ?? 0);
        $channelId = (int) ($input['channel_id'] ?? 0);
        $cycleType = (int) ($input['cycle_type'] ?? TradeConstant::SETTLEMENT_CYCLE_OTHER);
        $cycleKey = trim((string) ($input['cycle_key'] ?? ''));

        if ($merchantId <= 0 || $merchantGroupId <= 0 || $channelId <= 0 || $cycleKey === '') {
            throw new ValidationException('清算单入参不完整');
        }

        return $this->transactionRetry(function () use ($settleNo, $input, $items, $merchantId, $merchantGroupId, $channelId, $cycleType, $cycleKey) {
            // 先汇总主表金额，再写入主表和明细，保证批次头尾一致。
            $summary = $this->buildSummary($items, $input);
            $traceNo = trim((string) ($input['trace_no'] ?? $settleNo));

            $settlementOrder = $this->settlementOrderRepository->create([
                'settle_no' => $settleNo,
                'trace_no' => $traceNo,
                'merchant_id' => $merchantId,
                'merchant_group_id' => $merchantGroupId,
                'channel_id' => $channelId,
                'cycle_type' => $cycleType,
                'cycle_key' => $cycleKey,
                'status' => (int) ($input['status'] ?? TradeConstant::SETTLEMENT_STATUS_PENDING),
                'gross_amount' => $summary['gross_amount'],
                'fee_amount' => $summary['fee_amount'],
                'refund_amount' => $summary['refund_amount'],
                'fee_reverse_amount' => $summary['fee_reverse_amount'],
                'net_amount' => $summary['net_amount'],
                'accounted_amount' => $summary['accounted_amount'],
                'generated_at' => $input['generated_at'] ?? $this->now(),
                'failed_at' => null,
                'ext_json' => $input['ext_json'] ?? [],
            ]);

            foreach ($items as $item) {
                // 每一笔清算明细都单独落库，方便后续对账和问题定位。
                $this->settlementItemRepository->create([
                    'settle_no' => $settleNo,
                    'merchant_id' => $merchantId,
                    'merchant_group_id' => $merchantGroupId,
                    'channel_id' => $channelId,
                    'pay_no' => (string) ($item['pay_no'] ?? ''),
                    'refund_no' => (string) ($item['refund_no'] ?? ''),
                    'pay_amount' => (int) ($item['pay_amount'] ?? 0),
                    'fee_amount' => (int) ($item['fee_amount'] ?? 0),
                    'refund_amount' => (int) ($item['refund_amount'] ?? 0),
                    'fee_reverse_amount' => (int) ($item['fee_reverse_amount'] ?? 0),
                    'net_amount' => (int) ($item['net_amount'] ?? 0),
                    'item_status' => (int) ($item['item_status'] ?? 0),
                ]);
            }

            return $settlementOrder->refresh();
        });
    }

    /**
     * 清算入账成功。
     *
     * 会把清算净额计入商户可提现余额，并同步标记清算单与清算明细为已完成。
     *
     * @param string $settleNo 结算单号
     * @return SettlementOrder 清算单记录
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function completeSettlement(string $settleNo): SettlementOrder
    {
        $shouldDispatchEvent = false;

        $settlementOrder = $this->transactionRetry(function () use ($settleNo, &$shouldDispatchEvent) {
            $settlementOrder = $this->settlementOrderRepository->findForUpdateBySettleNo($settleNo);
            if (!$settlementOrder) {
                throw new ResourceNotFoundException('清结算单不存在', ['settle_no' => $settleNo]);
            }

            $currentStatus = (int) $settlementOrder->status;
            // 已结算或已终态的单子直接返回，避免重复入账。
            if ($currentStatus === TradeConstant::SETTLEMENT_STATUS_SETTLED) {
                return $settlementOrder;
            }

            if (TradeConstant::isSettlementTerminalStatus($currentStatus)) {
                return $settlementOrder;
            }

            if (!in_array($currentStatus, TradeConstant::settlementMutableStatuses(), true)) {
                throw new BusinessStateException('清结算单状态不允许当前操作', [
                    'settle_no' => $settleNo,
                    'status' => $currentStatus,
                ]);
            }

            $items = $this->settlementItemRepository->listBySettleNo($settleNo);
            $lockedPayOrders = [];
            foreach ($items as $item) {
                $payNo = trim((string) ($item->pay_no ?? ''));
                if ($payNo === '') {
                    continue;
                }

                $payOrder = $this->payOrderRepository->findForUpdateByPayNo($payNo);
                if ($payOrder) {
                    // 清算入账前锁定并检查支付单，避免冻结单继续入账。
                    $this->payOrderRiskControlService->assertNotFrozen($payOrder, '清算入账');
                    $lockedPayOrders[$payNo] = $payOrder;
                }
            }

            $this->refreshSettlementAmountsBeforeAccount($settlementOrder, $items, $lockedPayOrders);

            if ((int) $settlementOrder->accounted_amount > 0) {
                // 只有净额大于 0 时才入账到商户可提现余额。
                $this->merchantAccountService->creditAvailableAmountInCurrentTransaction(
                    (int) $settlementOrder->merchant_id,
                    (int) $settlementOrder->accounted_amount,
                    $settleNo,
                    'SETTLEMENT_CREDIT:' . $settleNo,
                    [
                        'settle_no' => $settleNo,
                        'remark' => '清算入账',
                    ],
                    (string) ($settlementOrder->trace_no ?: $settleNo)
                );
            }

            $settlementOrder->status = TradeConstant::SETTLEMENT_STATUS_SETTLED;
            $settlementOrder->accounted_at = $this->now();
            $settlementOrder->completed_at = $this->now();
            $settlementOrder->save();

            foreach ($items as $item) {
                // 清算明细和关联支付单状态一起同步，避免批次与订单状态不一致。
                $item->item_status = TradeConstant::SETTLEMENT_STATUS_SETTLED;
                $item->save();

                if (!empty($item->pay_no)) {
                    $payOrder = $lockedPayOrders[(string) $item->pay_no] ?? null;
                    if ($payOrder) {
                        $payOrder->settlement_status = TradeConstant::SETTLEMENT_STATUS_SETTLED;
                        $payOrder->save();
                    }
                }
            }

            $shouldDispatchEvent = true;

            return $settlementOrder->refresh();
        });

        if ($shouldDispatchEvent) {
            $this->dispatchSettlementOrderEvent(EventConstant::SETTLEMENT_ORDER_SUCCEEDED, $settlementOrder);
        }

        return $settlementOrder;
    }

    /**
     * 清算失败。
     *
     * 仅用于清算批次未成功入账时的终态标记。
     *
     * @param string $settleNo 结算单号
     * @param string $reason 失败原因
     * @return SettlementOrder 清算单记录
     * @throws ResourceNotFoundException
     * @throws BusinessStateException
     */
    public function failSettlement(string $settleNo, string $reason = ''): SettlementOrder
    {
        $shouldDispatchEvent = false;

        $settlementOrder = $this->transactionRetry(function () use ($settleNo, $reason, &$shouldDispatchEvent) {
            $settlementOrder = $this->settlementOrderRepository->findForUpdateBySettleNo($settleNo);
            if (!$settlementOrder) {
                throw new ResourceNotFoundException('清结算单不存在', ['settle_no' => $settleNo]);
            }

            $currentStatus = (int) $settlementOrder->status;
            // 失败态也只处理可变状态，终态直接返回。
            if ($currentStatus === TradeConstant::SETTLEMENT_STATUS_REVERSED) {
                return $settlementOrder;
            }

            if (TradeConstant::isSettlementTerminalStatus($currentStatus)) {
                return $settlementOrder;
            }

            if (!in_array($currentStatus, TradeConstant::settlementMutableStatuses(), true)) {
                throw new BusinessStateException('清结算单状态不允许当前操作', [
                    'settle_no' => $settleNo,
                    'status' => $currentStatus,
                ]);
            }

            $settlementOrder->status = TradeConstant::SETTLEMENT_STATUS_REVERSED;
            $settlementOrder->fail_reason = $reason;
            $settlementOrder->failed_at = $this->now();
            $extJson = (array) $settlementOrder->ext_json;
            if (trim($reason) !== '') {
                // 把失败原因同步到扩展字段，便于后台排查。
                $extJson['fail_reason'] = $reason;
            }
            $settlementOrder->ext_json = $extJson;
            $settlementOrder->save();

            $items = $this->settlementItemRepository->listBySettleNo($settleNo);
            foreach ($items as $item) {
                $item->item_status = TradeConstant::SETTLEMENT_STATUS_REVERSED;
                $item->save();
            }

            $shouldDispatchEvent = true;

            return $settlementOrder->refresh();
        });

        if ($shouldDispatchEvent) {
            $this->dispatchSettlementOrderEvent(EventConstant::SETTLEMENT_ORDER_FAILED, $settlementOrder);
        }

        return $settlementOrder;
    }

    /**
     * 入账前按支付单和已成功退款重算清算金额。
     *
     * @param SettlementOrder $settlementOrder 清算单
     * @param iterable $items 清算明细
     * @param array<string, \app\model\payment\PayOrder> $lockedPayOrders 已锁定支付单
     * @return void
     */
    private function refreshSettlementAmountsBeforeAccount(SettlementOrder $settlementOrder, iterable $items, array $lockedPayOrders): void
    {
        $grossAmount = 0;
        $feeAmount = 0;
        $refundAmount = 0;
        $feeReverseAmount = 0;
        $netAmount = 0;
        $hasPayItems = false;

        foreach ($items as $item) {
            $payNo = trim((string) ($item->pay_no ?? ''));
            $payOrder = $payNo !== '' ? ($lockedPayOrders[$payNo] ?? null) : null;
            if (!$payOrder) {
                continue;
            }

            $hasPayItems = true;
            $itemPayAmount = (int) $payOrder->pay_amount;
            $itemFeeAmount = (int) $payOrder->service_fee_amount;
            $itemRefundAmount = 0;
            $itemFeeReverseAmount = 0;

            $refunds = $this->refundOrderRepository->listForUpdateByPayNoAndStatuses($payNo, [
                TradeConstant::REFUND_STATUS_SUCCESS,
            ], ['refund_amount', 'fee_reverse_amount']);
            foreach ($refunds as $refund) {
                $itemRefundAmount += (int) $refund->refund_amount;
                $itemFeeReverseAmount += (int) $refund->fee_reverse_amount;
            }

            $itemNetAmount = max(0, $itemPayAmount - $itemFeeAmount - $itemRefundAmount + $itemFeeReverseAmount);

            $item->pay_amount = $itemPayAmount;
            $item->fee_amount = $itemFeeAmount;
            $item->refund_amount = $itemRefundAmount;
            $item->fee_reverse_amount = $itemFeeReverseAmount;
            $item->net_amount = $itemNetAmount;
            $item->save();

            $grossAmount += $itemPayAmount;
            $feeAmount += $itemFeeAmount;
            $refundAmount += $itemRefundAmount;
            $feeReverseAmount += $itemFeeReverseAmount;
            $netAmount += $itemNetAmount;
        }

        if (!$hasPayItems) {
            return;
        }

        $settlementOrder->gross_amount = $grossAmount;
        $settlementOrder->fee_amount = $feeAmount;
        $settlementOrder->refund_amount = $refundAmount;
        $settlementOrder->fee_reverse_amount = $feeReverseAmount;
        $settlementOrder->net_amount = $netAmount;
        $settlementOrder->accounted_amount = $netAmount;
        $settlementOrder->save();
    }

    /**
     * 发送清算单事件。
     *
     * @param string $eventName 事件名称
     * @param SettlementOrder $settlementOrder 清算单
     * @return void
     */
    private function dispatchSettlementOrderEvent(string $eventName, SettlementOrder $settlementOrder): void
    {
        Event::dispatch($eventName, [
            'settle_no' => (string) $settlementOrder->settle_no,
            'settlement_order' => $settlementOrder,
        ]);
    }

    /**
     * 根据清算明细构造汇总数据。
     *
     * @param array $items 清算明细
     * @param array $input 清算参数
     * @return array 汇总数据
     */
    private function buildSummary(array $items, array $input): array
    {
        if (!empty($items)) {
            $grossAmount = 0;
            $feeAmount = 0;
            $refundAmount = 0;
            $feeReverseAmount = 0;
            $netAmount = 0;

            foreach ($items as $item) {
                // 汇总字段都从明细逐项累加，避免依赖上游传入的批次统计值。
                $grossAmount += (int) ($item['pay_amount'] ?? 0);
                $feeAmount += (int) ($item['fee_amount'] ?? 0);
                $refundAmount += (int) ($item['refund_amount'] ?? 0);
                $feeReverseAmount += (int) ($item['fee_reverse_amount'] ?? 0);
                $netAmount += (int) ($item['net_amount'] ?? 0);
            }

            return [
                'gross_amount' => $grossAmount,
                'fee_amount' => $feeAmount,
                'refund_amount' => $refundAmount,
                'fee_reverse_amount' => $feeReverseAmount,
                'net_amount' => $netAmount,
                'accounted_amount' => $input['accounted_amount'] ?? $netAmount,
            ];
        }

        // 明细为空时，直接使用外部传入的汇总字段，兼容上游已经算好的批次数据。
        return [
            'gross_amount' => (int) ($input['gross_amount'] ?? 0),
            'fee_amount' => (int) ($input['fee_amount'] ?? 0),
            'refund_amount' => (int) ($input['refund_amount'] ?? 0),
            'fee_reverse_amount' => (int) ($input['fee_reverse_amount'] ?? 0),
            'net_amount' => (int) ($input['net_amount'] ?? 0),
            'accounted_amount' => (int) ($input['accounted_amount'] ?? 0),
        ];
    }
}
