<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\NotifyConstant;
use app\common\constant\RouteConstant;
use app\common\constant\TradeConstant;
use app\exception\BusinessStateException;
use app\exception\ConflictException;
use app\exception\ValidationException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\BizOrderRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\account\funds\MerchantAccountService;
use app\service\merchant\MerchantService;
use app\service\payment\runtime\PaymentRouteService;

/**
 * 支付单发起服务。
 *
 * 负责支付单预创建、通道路由选择、第三方装单和首轮状态落库。
 *
 * @property MerchantService $merchantService 商户服务
 * @property PaymentRouteService $paymentRouteService 支付路由服务
 * @property MerchantAccountService $merchantAccountService 商户账户服务
 * @property BizOrderRepository $bizOrderRepository 业务订单仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 * @property PayOrderChannelDispatchService $payOrderChannelDispatchService 支付单渠道派发服务
 */
class PayOrderAttemptService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantService $merchantService 商户服务
     * @param PaymentRouteService $paymentRouteService 支付路由服务
     * @param MerchantAccountService $merchantAccountService 商户账户服务
     * @param BizOrderRepository $bizOrderRepository 业务订单仓库
     * @param PayOrderRepository $payOrderRepository 支付订单仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @param PayOrderChannelDispatchService $payOrderChannelDispatchService 支付单渠道派发服务
     */
    public function __construct(
        protected MerchantService $merchantService,
        protected PaymentRouteService $paymentRouteService,
        protected MerchantAccountService $merchantAccountService,
        protected BizOrderRepository $bizOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PayOrderChannelDispatchService $payOrderChannelDispatchService
    ) {
    }

    /**
     * 预创建支付尝试。
     *
     * 该方法会完成商户、支付方式、路由、通道限额、串行尝试和自有通道手续费预占的完整预检查。
     *
     * @param array $input 支付预创建参数
     * @return array 发起结果
     * @throws ValidationException
     * @throws BusinessStateException
     * @throws ConflictException
     */
    public function preparePayAttempt(array $input): array
    {
        $merchantId = (int) ($input['merchant_id'] ?? 0);
        $merchantOrderNo = trim((string) ($input['merchant_order_no'] ?? ''));
        $payTypeId = (int) ($input['pay_type_id'] ?? 0);
        $payAmount = (int) ($input['pay_amount'] ?? 0);

        if ($merchantId <= 0 || $merchantOrderNo === '' || $payTypeId <= 0 || $payAmount <= 0) {
            throw new ValidationException('支付入参不完整');
        }

        // 先校验商户和支付方式是否可用，避免进入事务后才发现前置条件不满足。
        $merchant = $this->merchantService->ensureMerchantEnabled($merchantId);
        $merchantGroupId = (int) $merchant->group_id;
        if ($merchantGroupId <= 0) {
            throw new ValidationException('商户未配置分组', ['merchant_id' => $merchantId]);
        }
        $this->merchantService->ensureMerchantGroupEnabled($merchantGroupId);

        /** @var PaymentType|null $paymentType */
        $paymentType = $this->paymentTypeRepository->find($payTypeId);
        if (!$paymentType || (int) $paymentType->status !== CommonConstant::STATUS_ENABLED) {
            throw new BusinessStateException('支付方式不支持', ['pay_type_id' => $payTypeId]);
        }

        // 根据商户分组、支付金额和请求参数选择可用通道。
        $route = $this->paymentRouteService->resolveByMerchantGroup($merchantGroupId, $payTypeId, $payAmount, $input);
        $selected = $route['selected_channel'];
        /** @var PaymentChannel $channel */
        $channel = $selected['channel'];

        $payNo = $this->generateNo('PAY');
        $channelRequestNo = $this->generateNo('REQ');

        $prepared = $this->transactionRetry(function () use (
            $input,
            $merchant,
            $merchantId,
            $merchantGroupId,
            $merchantOrderNo,
            $payTypeId,
            $payAmount,
            $route,
            $channel,
            $payNo,
            $channelRequestNo
        ) {
            // 在事务中完成业务单和支付单的原子创建，保证幂等与状态一致。
            $existingBizOrder = $this->bizOrderRepository->findForUpdateByMerchantAndOrderNo($merchantId, $merchantOrderNo);
            $bizTraceNo = '';

            if ($existingBizOrder) {
                // 同一商户订单号只能复用原业务单，且金额必须完全一致。
                if ((int) $existingBizOrder->order_amount !== $payAmount) {
                    throw new ValidationException('同一商户订单号金额不一致', [
                        'merchant_id' => $merchantId,
                        'merchant_order_no' => $merchantOrderNo,
                    ]);
                }

                if (in_array((int) $existingBizOrder->status, [
                    TradeConstant::ORDER_STATUS_SUCCESS,
                    TradeConstant::ORDER_STATUS_CLOSED,
                    TradeConstant::ORDER_STATUS_TIMEOUT,
                ], true)) {
                    throw new BusinessStateException('支付单状态不允许重复创建', [
                        'biz_no' => (string) $existingBizOrder->biz_no,
                        'status' => (int) $existingBizOrder->status,
                    ]);
                }

                if (!empty($existingBizOrder->active_pay_no)) {
                    $activePayOrder = $this->payOrderRepository->findForUpdateByPayNo((string) $existingBizOrder->active_pay_no);
                    if ($activePayOrder && in_array((int) $activePayOrder->status, [TradeConstant::ORDER_STATUS_CREATED, TradeConstant::ORDER_STATUS_PAYING], true)) {
                        throw new ConflictException('重复请求', [
                            'biz_no' => (string) $existingBizOrder->biz_no,
                            'active_pay_no' => (string) $existingBizOrder->active_pay_no,
                        ]);
                    }
                }

                $bizOrder = $existingBizOrder;
                $bizTraceNo = trim((string) ($bizOrder->trace_no ?? ''));
                if ($bizTraceNo === '') {
                    // 旧单如果没有 trace_no，就补成业务单号，方便后续串起来查。
                    $bizTraceNo = (string) $bizOrder->biz_no;
                    $bizOrder->trace_no = $bizTraceNo;
                }
                $attemptNo = (int) $bizOrder->attempt_count + 1;
            } else {
                $bizOrder = $this->bizOrderRepository->create([
                    'biz_no' => $this->generateNo('BIZ'),
                    'trace_no' => $this->generateNo('TRC'),
                    'merchant_id' => $merchantId,
                    'merchant_group_id' => $merchantGroupId,
                    'poll_group_id' => (int) $route['poll_group']->id,
                    'merchant_order_no' => $merchantOrderNo,
                    'subject' => (string) ($input['subject'] ?? ''),
                    'body' => (string) ($input['body'] ?? ''),
                    'order_amount' => $payAmount,
                    'paid_amount' => 0,
                    'refund_amount' => 0,
                    'status' => TradeConstant::ORDER_STATUS_CREATED,
                    'attempt_count' => 0,
                    'ext_json' => $input['ext_json'] ?? [],
                ]);
                $bizTraceNo = (string) $bizOrder->trace_no;
                $attemptNo = 1;
            }

            $feeRateBp = (int) $channel->cost_rate_bp;
            $splitRateBp = (int) $channel->split_rate_bp ?: 10000;
            // 手续费和分账费率都按快照落库，后续配置变化不会影响这笔单的口径。
            $feeEstimated = $this->calculateAmountByBp($payAmount, $feeRateBp);

            if ((int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_SELF && $feeEstimated > 0) {
                // 自有通道先冻结预估手续费，避免后续余额不足。
                $this->merchantAccountService->freezeAmountInCurrentTransaction(
                    $merchantId,
                    $feeEstimated,
                    $payNo,
                    'PAY_FREEZE:' . $payNo,
                    [
                        'merchant_order_no' => $merchantOrderNo,
                        'pay_type_id' => $payTypeId,
                        'channel_id' => (int) $channel->id,
                        'remark' => '自有通道手续费预占',
                    ],
                    $bizTraceNo
                );
            }

            $payOrder = $this->payOrderRepository->create([
                'pay_no' => $payNo,
                'biz_no' => (string) $bizOrder->biz_no,
                'trace_no' => $bizTraceNo,
                'merchant_id' => $merchantId,
                'merchant_group_id' => $merchantGroupId,
                'poll_group_id' => (int) $route['poll_group']->id,
                'attempt_no' => (int) $attemptNo,
                'channel_id' => (int) $channel->id,
                'pay_type_id' => $payTypeId,
                'plugin_code' => (string) $channel->plugin_code,
                'channel_type' => (int) $channel->channel_mode,
                'channel_mode' => (int) $channel->channel_mode,
                'pay_amount' => $payAmount,
                'fee_rate_bp_snapshot' => $feeRateBp,
                'split_rate_bp_snapshot' => $splitRateBp,
                'fee_estimated_amount' => $feeEstimated,
                'fee_actual_amount' => 0,
                'status' => TradeConstant::ORDER_STATUS_PAYING,
                'fee_status' => (int) $channel->channel_mode === RouteConstant::CHANNEL_MODE_SELF ? TradeConstant::FEE_STATUS_FROZEN : TradeConstant::FEE_STATUS_NONE,
                'settlement_status' => TradeConstant::SETTLEMENT_STATUS_NONE,
                'channel_request_no' => $channelRequestNo,
                'request_at' => $this->now(),
                'callback_status' => NotifyConstant::PROCESS_STATUS_PENDING,
                'callback_times' => 0,
                'ext_json' => array_merge($input['ext_json'] ?? [], [
                    'merchant_no' => (string) $merchant->merchant_no,
                    'merchant_group_id' => $merchantGroupId,
                    'poll_group_id' => (int) $route['poll_group']->id,
                    'channel_id' => (int) $channel->id,
                    'channel_mode' => (int) $channel->channel_mode,
                    'trace_no' => $bizTraceNo,
                ]),
            ]);

            $bizOrder->active_pay_no = (string) $payOrder->pay_no;
            $bizOrder->attempt_count = (int) $attemptNo;
            $bizOrder->status = TradeConstant::ORDER_STATUS_PAYING;
            $bizOrder->merchant_group_id = $merchantGroupId;
            $bizOrder->poll_group_id = (int) $route['poll_group']->id;
            if ($bizTraceNo !== '' && (string) ($bizOrder->trace_no ?? '') === '') {
                // 把追踪号回写到业务单上，后续查单和对账能串到同一条链路。
                $bizOrder->trace_no = $bizTraceNo;
            }
            $bizOrder->save();

            return [
                'merchant' => $merchant,
                'biz_order' => $bizOrder->refresh(),
                'pay_order' => $payOrder,
                'route' => $route,
            ];
        });

        /** @var PayOrder $payOrder */
        $payOrder = $prepared['pay_order'];
        /** @var BizOrder $bizOrder */
        $bizOrder = $prepared['biz_order'];
        /** @var \app\model\payment\PaymentChannel $channel */
        $channel = $prepared['route']['selected_channel']['channel'];

        // 支付单落库后立即拉起渠道订单，补全渠道返回的单号和参数快照。
        $channelDispatchResult = $this->payOrderChannelDispatchService->dispatch($payOrder, $bizOrder, $channel);

        $prepared['pay_order'] = $channelDispatchResult['pay_order'];
        $prepared['payment_result'] = $channelDispatchResult['payment_result'];
        $prepared['pay_params'] = $channelDispatchResult['pay_params'];

        return $prepared;
    }

    /**
     * 计算手续费金额。
     *
     * @param int $amount 金额（分）
     * @param int $bp 费率基点，`10000` 表示 100%
     * @return int 手续费金额（分）
     */
    private function calculateAmountByBp(int $amount, int $bp): int
    {
        if ($amount <= 0 || $bp <= 0) {
            return 0;
        }

        // 基点换算统一向下取整，避免手续费计算时出现超扣。
        return (int) floor($amount * $bp / 10000);
    }
}
