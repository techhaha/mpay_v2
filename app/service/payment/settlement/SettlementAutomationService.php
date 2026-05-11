<?php

namespace app\service\payment\settlement;

use app\common\base\BaseService;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\model\payment\PayOrder;
use app\model\payment\SettlementOrder;
use app\repository\merchant\base\MerchantPolicyRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\settlement\SettlementOrderRepository;
use app\repository\payment\trade\PayOrderRepository;

/**
 * 清算自动化服务。
 *
 * 支付成功后为平台代收订单生成清算单；满足商户自动入账策略时再进入清算入账。
 */
class SettlementAutomationService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param SettlementService $settlementService 清算服务
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付通道仓库
     * @param PaymentPluginConfRepository $paymentPluginConfRepository 插件配置仓库
     * @param MerchantPolicyRepository $merchantPolicyRepository 商户策略仓库
     * @param SettlementOrderRepository $settlementOrderRepository 清算单仓库
     * @return void
     */
    public function __construct(
        protected SettlementService $settlementService,
        protected PayOrderRepository $payOrderRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected MerchantPolicyRepository $merchantPolicyRepository,
        protected SettlementOrderRepository $settlementOrderRepository
    ) {
    }

    /**
     * 为已成功的平台代收支付单生成清算单。
     *
     * @param PayOrder $payOrder 支付单
     * @return SettlementOrder|null 清算单；非平台代收或非成功支付返回 null
     */
    public function createForPaidPayOrder(PayOrder $payOrder): ?SettlementOrder
    {
        if ((int) $payOrder->status !== TradeConstant::ORDER_STATUS_SUCCESS) {
            return null;
        }

        if ((int) $payOrder->channel_type !== RouteConstant::CHANNEL_MODE_COLLECT) {
            return null;
        }

        $payNo = (string) $payOrder->pay_no;
        $payAmount = (int) $payOrder->pay_amount;
        $serviceFee = (int) $payOrder->service_fee_amount;
        $netAmount = max(0, $payAmount - $serviceFee);

        return $this->settlementService->createSettlementOrder([
            'settle_no' => $this->settleNoForPayNo($payNo),
            'trace_no' => (string) ($payOrder->trace_no ?: $payOrder->biz_no),
            'merchant_id' => (int) $payOrder->merchant_id,
            'merchant_group_id' => (int) $payOrder->merchant_group_id,
            'channel_id' => (int) $payOrder->channel_id,
            'cycle_type' => $this->resolveCycleType($payOrder),
            'cycle_key' => $payNo,
            'accounted_amount' => $netAmount,
            'status' => TradeConstant::SETTLEMENT_STATUS_PENDING,
            'ext_json' => [],
        ], [[
            'pay_no' => $payNo,
            'refund_no' => '',
            'pay_amount' => $payAmount,
            'fee_amount' => $serviceFee,
            'refund_amount' => 0,
            'fee_reverse_amount' => 0,
            'net_amount' => $netAmount,
            'item_status' => TradeConstant::SETTLEMENT_STATUS_PENDING,
        ]]);
    }

    /**
     * 判断清算单是否允许自动入账。
     *
     * @param SettlementOrder $settlementOrder 清算单
     * @return bool 是否允许自动入账
     */
    public function shouldAutoComplete(SettlementOrder $settlementOrder): bool
    {
        if ((int) $settlementOrder->status !== TradeConstant::SETTLEMENT_STATUS_PENDING) {
            return false;
        }

        $policy = $this->merchantPolicyRepository->findByMerchantId((int) $settlementOrder->merchant_id);
        if (!$policy || (int) $policy->auto_payout !== 1) {
            return false;
        }

        $minSettlementAmount = (int) $policy->min_settlement_amount;
        if ($minSettlementAmount > 0 && (int) $settlementOrder->accounted_amount < $minSettlementAmount) {
            return false;
        }

        return true;
    }

    /**
     * 执行自动清算入账。
     *
     * @param string $settleNo 清算单号
     * @return SettlementOrder|null 清算单
     */
    public function completeAutoSettlement(string $settleNo): ?SettlementOrder
    {
        $settlementOrder = $this->settlementOrderRepository->findBySettleNo($settleNo);
        if (!$settlementOrder) {
            return null;
        }

        if (!$this->shouldAutoComplete($settlementOrder)) {
            return $settlementOrder;
        }

        return $this->settlementService->completeSettlement($settleNo);
    }

    /**
     * 按支付单生成稳定清算单号。
     *
     * @param string $payNo 支付单号
     * @return string 清算单号
     */
    private function settleNoForPayNo(string $payNo): string
    {
        return 'STL' . substr($payNo, 3);
    }

    /**
     * 解析清算周期。
     *
     * 商户策略优先，其次使用通道绑定的插件配置，最后默认 D1。
     *
     * @param PayOrder $payOrder 支付单
     * @return int 清算周期
     */
    private function resolveCycleType(PayOrder $payOrder): int
    {
        $policy = $this->merchantPolicyRepository->findByMerchantId((int) $payOrder->merchant_id);
        if ($policy) {
            return (int) $policy->settlement_cycle_override;
        }

        $channel = $this->paymentChannelRepository->find((int) $payOrder->channel_id);
        if (!$channel || (int) $channel->api_config_id <= 0) {
            return TradeConstant::SETTLEMENT_CYCLE_D1;
        }

        $pluginConf = $this->paymentPluginConfRepository->find((int) $channel->api_config_id);

        return $pluginConf ? (int) $pluginConf->settlement_cycle_type : TradeConstant::SETTLEMENT_CYCLE_D1;
    }
}
