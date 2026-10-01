<?php

declare(strict_types=1);

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\TradeConstant;
use app\common\interface\RefundNotifyInterface;
use app\common\interface\RefundQueryInterface;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\ResourceNotFoundException;
use app\exception\UnsupportedPaymentOperationException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\model\payment\RefundOrder;
use app\repository\payment\trade\PayOrderRepository;
use app\repository\payment\trade\RefundOrderRepository;
use app\service\payment\runtime\PaymentPluginManager;
use support\Log;
use Throwable;

/**
 * 退款通道派发服务。
 *
 * 同一退款单只有取得派发权的调用者可以请求上游；无法确认上游是否受理时，
 * 退款单保持处理中并等待查询或通知给出可信终态。
 */
class RefundDispatchService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param RefundLifecycleService $refundLifecycleService 退款生命周期服务
     * @param RefundOrderRepository $refundOrderRepository 退款单仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PayOrderRiskControlService $payOrderRiskControlService 支付单风控服务
     */
    public function __construct(
        protected RefundLifecycleService $refundLifecycleService,
        protected RefundOrderRepository $refundOrderRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PaymentPluginManager $paymentPluginManager,
        protected PayOrderRiskControlService $payOrderRiskControlService
    ) {
    }

    /**
     * 请求上游处理退款。
     *
     * @param RefundOrder|string $refund 退款单或退款单号
     * @param bool $isRetry 是否为失败单重试
     * @param bool $throwOnFailure 是否向调用方抛出失败
     * @return RefundOrder 最新退款单
     */
    public function dispatch(RefundOrder|string $refund, bool $isRetry = false, bool $throwOnFailure = false): RefundOrder
    {
        $refundOrder = $this->resolveRefundOrder($refund);
        $refundNo = (string) $refundOrder->refund_no;
        $claimed = false;
        $requestStarted = false;
        try {
            $payOrder = $this->requirePayOrder($refundOrder);
            $this->payOrderRiskControlService->assertNotFrozen($payOrder, '退款派发');
            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            $claim = $this->refundLifecycleService->claimRefundDispatch($refundNo, $isRetry, $plugin instanceof RefundQueryInterface);
            $refundOrder = $claim['refund_order'];
            if (!$claim['claimed']) {
                return $refundOrder;
            }
            $claimed = true;
            $payload = $this->buildPluginRefundPayload(
                $payOrder,
                $refundOrder,
                $plugin instanceof RefundNotifyInterface
            );
            $requestStarted = true;
            $result = $plugin->refund($payload);
            $result = $this->validateRefundResult($refundOrder, $payOrder, $result, 'refund_result');
            $channelRefundNo = (string) ($result['chan_refund_no'] ?? '');

            if ((string) $result['status'] === PaymentPluginStatusConstant::SUCCESS) {
                return $this->refundLifecycleService->markRefundSuccess($refundNo, [
                    'succeeded_at' => $this->now(),
                    'channel_refund_no' => $channelRefundNo,
                ]);
            }

            return $this->refundLifecycleService->recordRefundProgress($refundNo, $channelRefundNo);
        } catch (Throwable $e) {
            if (!$claimed) {
                $latest = $this->refundLifecycleService->recordRefundDispatchError($refundNo, $e->getMessage(), $isRetry);
            } elseif ($e instanceof UnsupportedPaymentOperationException || !$requestStarted) {
                $latest = $this->releaseClaim($refundNo, $isRetry, $e->getMessage());
            } elseif ($e instanceof PaymentDefinitiveException) {
                $latest = $this->refundLifecycleService->markRefundFailed($refundNo, [
                    'failed_at' => $this->now(),
                    'last_error' => $e->getMessage(),
                ]);
            } else {
                // 上游可能已受理，只保留处理中事实；查询任务已在取得派发权时提交。
                Log::warning(sprintf(
                    '[RefundDispatch] 退款请求结果未确认 refund_no=%s exception=%s error=%s',
                    $refundNo, $e::class, $e->getMessage()
                ));
                $latest = $this->refundLifecycleService->recordRefundQueryAttempt($refundNo, 'error', $e->getMessage());
            }
            if ($throwOnFailure) {
                if ($e instanceof PaymentException) {
                    throw $e;
                }
                $exception = $requestStarted ? PaymentUncertainException::class : PaymentException::class;
                throw new $exception($requestStarted ? '退款请求结果不确定' : '退款发起失败', 40200, [
                    'refund_no' => $refundNo,
                    'exception_class' => $e::class,
                ]);
            }

            return $latest;
        }
    }

    /**
     * 查询上游退款状态并按可信结果推进生命周期。
     *
     * @param RefundOrder|string $refund 退款单或退款单号
     * @return RefundOrder 最新退款单
     */
    public function queryStatus(RefundOrder|string $refund): RefundOrder
    {
        $refundOrder = $this->resolveRefundOrder($refund);
        if (!in_array((int) $refundOrder->status, [
            TradeConstant::REFUND_STATUS_PROCESSING,
            TradeConstant::REFUND_STATUS_FAILED,
        ], true)) {
            return $refundOrder;
        }

        try {
            $payOrder = $this->requirePayOrder($refundOrder);
            $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
            if (!$plugin instanceof RefundQueryInterface) {
                throw new UnsupportedPaymentOperationException('当前插件不支持退款主动查询', 40200);
            }

            $result = $plugin->queryRefund($this->buildPluginRefundPayload($payOrder, $refundOrder, false));
            $result = $this->validateRefundResult($refundOrder, $payOrder, $result, 'refund_status_result');
            $refundNo = (string) $refundOrder->refund_no;
            $channelRefundNo = (string) ($result['chan_refund_no'] ?? '');
            // 终态与引用同事务接纳，不能先提交进度号再做可能回滚的资金冲正。
            if ((string) $result['status'] === PaymentPluginStatusConstant::SUCCESS) {
                return $this->refundLifecycleService->markRefundSuccess($refundNo, ['channel_refund_no' => $channelRefundNo]);
            }
            if ((string) $result['status'] === PaymentPluginStatusConstant::FAILED) {
                return $this->refundLifecycleService->markRefundFailed($refundNo, [
                    'channel_refund_no' => $channelRefundNo,
                    'last_error' => (string) ($result['message'] ?? '渠道退款失败'),
                ]);
            }

            $this->refundLifecycleService->recordRefundProgress($refundNo, $channelRefundNo);
            return $this->refundLifecycleService->recordRefundQueryAttempt($refundNo, 'pending', (string) ($result['message'] ?? ''));
        } catch (Throwable $e) {
            $this->refundLifecycleService->recordRefundQueryAttempt(
                (string) $refundOrder->refund_no,
                'error',
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * 解析退款单模型。
     *
     * @param RefundOrder|string $refund 退款单或退款单号
     * @return RefundOrder 退款单
     */
    private function resolveRefundOrder(RefundOrder|string $refund): RefundOrder
    {
        if ($refund instanceof RefundOrder) {
            return $refund;
        }

        $refundOrder = $this->refundOrderRepository->findByRefundNo($refund);
        if (!$refundOrder) {
            throw new ResourceNotFoundException('退款单不存在', ['refund_no' => $refund]);
        }

        return $refundOrder;
    }

    /**
     * 加载退款对应的原支付单并校验通道归属。
     *
     * @param RefundOrder $refundOrder 退款单
     * @return PayOrder 原支付单
     */
    private function requirePayOrder(RefundOrder $refundOrder): PayOrder
    {
        $payOrder = $this->payOrderRepository->findByPayNo((string) $refundOrder->pay_no);
        if (!$payOrder) {
            throw new ResourceNotFoundException('原支付单不存在', ['pay_no' => (string) $refundOrder->pay_no]);
        }
        if ((int) $payOrder->channel_id !== (int) $refundOrder->channel_id) {
            throw new ValidationException('退款单与原支付单通道不一致');
        }

        return $payOrder;
    }

    /**
     * 构建插件退款标准参数。
     *
     * @param PayOrder $payOrder 原支付单
     * @param RefundOrder $refundOrder 退款单
     * @param bool $includeCallback 是否包含退款通知地址
     * @return array<string, mixed>
     */
    private function buildPluginRefundPayload(
        PayOrder $payOrder,
        RefundOrder $refundOrder,
        bool $includeCallback
    ): array {
        $payload = [
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'chan_order_no' => (string) $payOrder->channel_order_no,
            'chan_trade_no' => (string) $payOrder->channel_trade_no,
            'amount' => (int) $payOrder->pay_amount,
            'pay_created_at' => (string) ($payOrder->created_at ?? ''),
            'client_ip' => (string) ($payOrder->client_ip ?? ''),
            '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
            'pay_type_id' => (int) $payOrder->pay_type_id,
            'refund_no' => (string) $refundOrder->refund_no,
            'merchant_refund_no' => (string) $refundOrder->merchant_refund_no,
            'refund_amount' => (int) $refundOrder->refund_amount,
            'refund_reason' => (string) $refundOrder->reason,
            'channel_request_no' => (string) $refundOrder->channel_request_no,
            'chan_refund_no' => (string) $refundOrder->channel_refund_no,
        ] + $this->paymentContext($payOrder);
        if ($includeCallback) {
            $payload['refund_callback_url'] = $this->refundCallbackUrl((string) $refundOrder->refund_no);
        }

        return $payload;
    }

    /**
     * 读取原支付请求实际使用的渠道产品。
     *
     * @param PayOrder $payOrder 原支付单
     * @return array{pay_type_code:string,pay_product:string,pay_action:string,channel_context:array<string,mixed>}
     */
    private function paymentContext(PayOrder $payOrder): array
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        if ($context === []) {
            throw new PaymentException('支付单缺少插件支付上下文', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }
        $payType = trim((string) ($context['pay_type'] ?? ''));
        $payProduct = trim((string) ($context['pay_product'] ?? ''));
        if ($payType === '' || $payProduct === '') {
            throw new PaymentException('支付单插件支付上下文不完整', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        return [
            'pay_type_code' => $payType,
            'pay_product' => $payProduct,
            'pay_action' => trim((string) ($context['pay_action'] ?? '')),
            'channel_context' => (array) ($context['channel_context'] ?? []),
        ];
    }

    /**
     * 校验插件退款结果与本地订单的一致性。
     *
     * @param RefundOrder $refundOrder 退款单
     * @param PayOrder $payOrder 原支付单
     * @param array<string, mixed> $result 插件标准退款结果
     * @param string $scene 校验场景
     * @return array<string, mixed> 已校验退款结果
     */
    private function validateRefundResult(
        RefundOrder $refundOrder,
        PayOrder $payOrder,
        array $result,
        string $scene
    ): array {
        $validated = PaymentPluginRefundResultValidator::make($result)
            ->withScene($scene)
            ->withException(PaymentUncertainException::class)
            ->validate();
        foreach ([
            'refund_no' => (string) $refundOrder->refund_no,
            'pay_no' => (string) $payOrder->pay_no,
        ] as $field => $expected) {
            if (!hash_equals($expected, (string) $validated[$field])) {
                throw new PaymentUncertainException('插件退款结果与本地订单不匹配', 40200, [
                    'refund_no' => (string) $refundOrder->refund_no,
                    'field' => $field,
                ]);
            }
        }
        if ((int) $validated['refund_amount'] !== (int) $refundOrder->refund_amount) {
            throw new PaymentUncertainException('插件退款结果金额不匹配', 40200, [
                'refund_no' => (string) $refundOrder->refund_no,
            ]);
        }

        $storedChannelRefundNo = trim((string) $refundOrder->channel_refund_no);
        $actualChannelRefundNo = trim((string) ($validated['chan_refund_no'] ?? ''));
        if ($storedChannelRefundNo !== ''
            && $actualChannelRefundNo !== ''
            && !hash_equals($storedChannelRefundNo, $actualChannelRefundNo)) {
            throw new PaymentUncertainException('插件退款结果渠道退款号不匹配', 40200, [
                'refund_no' => (string) $refundOrder->refund_no,
            ]);
        }

        return $validated;
    }

    /**
     * 在上游请求尚未发出时释放退款派发权。
     *
     * @param string $refundNo 退款单号
     * @param bool $isRetry 是否为失败单重试
     * @param string $reason 释放原因
     * @return RefundOrder 最新退款单
     */
    private function releaseClaim(string $refundNo, bool $isRetry, string $reason): RefundOrder
    {
        return $this->refundLifecycleService->releaseRefundDispatchClaim(
            $refundNo,
            $reason,
            $isRetry ? TradeConstant::REFUND_STATUS_FAILED : TradeConstant::REFUND_STATUS_CREATED
        );
    }

    /**
     * 生成独立退款通知地址。
     *
     * @param string $refundNo 退款单号
     * @return string 退款通知地址
     */
    private function refundCallbackUrl(string $refundNo): string
    {
        $siteUrl = rtrim(trim((string) sys_config('site_url')), '/');
        $scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        if ($refundNo === ''
            || !in_array($scheme, ['http', 'https'], true)
            || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('退款通知地址配置无效');
        }

        return $siteUrl . '/api/pay/refund/' . rawurlencode($refundNo) . '/callback';
    }
}
