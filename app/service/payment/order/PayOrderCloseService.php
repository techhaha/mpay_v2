<?php

declare(strict_types=1);

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\TradeConstant;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;
use app\exception\UnsupportedPaymentOperationException;
use app\exception\ValidationException;
use app\model\payment\PayOrder;
use app\service\payment\runtime\PaymentPluginManager;
use Throwable;

/**
 * 支付单渠道关单服务。
 *
 * 负责校验本地状态、调用原支付通道、核对关单结果并推进支付单生命周期。
 */
class PayOrderCloseService extends BaseService
{
    public function __construct(
        protected PaymentPluginManager $paymentPluginManager,
        protected PayOrderLifecycleService $payOrderLifecycleService
    ) {
    }

    /**
     * 关闭原支付通道订单。
     */
    public function close(PayOrder $payOrder): PayOrder
    {
        $status = (int) $payOrder->status;
        if ($status === TradeConstant::ORDER_STATUS_CLOSED) {
            return $payOrder;
        }
        if ($status === TradeConstant::ORDER_STATUS_SUCCESS) {
            throw new ValidationException('订单已支付成功，不能关闭');
        }
        if (TradeConstant::isOrderTerminalStatus($status)) {
            throw new ValidationException('订单已结束，不能关闭');
        }

        $plugin = $this->paymentPluginManager->createByPayOrder($payOrder, true);
        try {
            $result = $plugin->close($this->buildPluginClosePayload($payOrder));
        } catch (PaymentDefinitiveException|PaymentUncertainException|UnsupportedPaymentOperationException $e) {
            throw $e;
        } catch (PaymentException $e) {
            throw new PaymentUncertainException(
                $e->getMessage(),
                (int) ($e->getCode() ?: 40200),
                $e->getData()
            );
        } catch (Throwable $e) {
            throw new PaymentUncertainException('渠道关单结果不确定', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
                'exception_class' => $e::class,
            ]);
        }

        $result = PaymentPluginCloseResultValidator::make($result)
            ->withScene('close_result')
            ->withException(PaymentUncertainException::class)
            ->validate();
        if (!hash_equals((string) $payOrder->pay_no, (string) $result['pay_no'])) {
            throw new PaymentUncertainException('插件关单返回的支付单号不匹配', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
            ]);
        }

        foreach (['chan_order_no' => 'channel_order_no', 'chan_trade_no' => 'channel_trade_no'] as $field => $modelField) {
            $stored = trim((string) ($payOrder->{$modelField} ?? ''));
            $actual = trim((string) ($result[$field] ?? ''));
            if ($stored !== '' && $actual !== '' && !hash_equals($stored, $actual)) {
                throw new PaymentUncertainException('插件关单返回的渠道流水与支付单不匹配', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                    'field' => $field,
                ]);
            }
        }

        if ((string) $result['status'] !== PaymentPluginStatusConstant::CLOSED) {
            throw new PaymentUncertainException(
                (string) ($result['message'] ?? '渠道关单结果尚未确认'),
                40200,
                [
                    'pay_no' => (string) $payOrder->pay_no,
                    'channel_status' => (string) ($result['channel_status'] ?? ''),
                ]
            );
        }

        return $this->payOrderLifecycleService->closePayOrder((string) $payOrder->pay_no, [
            'closed_at' => $this->now(),
            'channel_order_no' => (string) ($result['chan_order_no'] ?? ''),
            'channel_trade_no' => (string) ($result['chan_trade_no'] ?? ''),
            'reason' => (string) ($result['message'] ?? '渠道订单已关闭'),
        ]);
    }

    /**
     * 构建关单插件标准参数。
     *
     * @return array<string, mixed>
     */
    private function buildPluginClosePayload(PayOrder $payOrder): array
    {
        return [
            'pay_no' => (string) $payOrder->pay_no,
            'biz_no' => (string) $payOrder->biz_no,
            'chan_order_no' => (string) ($payOrder->channel_order_no ?? ''),
            'chan_trade_no' => (string) ($payOrder->channel_trade_no ?? ''),
            'amount' => (int) $payOrder->pay_amount,
            'pay_created_at' => (string) ($payOrder->created_at ?? ''),
            'client_ip' => (string) ($payOrder->client_ip ?? ''),
            '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
            'pay_type_id' => (int) $payOrder->pay_type_id,
        ] + $this->paymentContext($payOrder);
    }

    /**
     * 读取原支付实际使用的插件产品上下文。
     *
     * @return array{pay_type_code:string,pay_product:string,pay_action:string,channel_context:array<string,mixed>}
     */
    private function paymentContext(PayOrder $payOrder): array
    {
        $extJson = (array) ($payOrder->ext_json ?? []);
        $context = (array) ($extJson['payment_context'] ?? []);
        $payType = trim((string) ($context['pay_type'] ?? ''));
        $payProduct = trim((string) ($context['pay_product'] ?? ''));
        if ($payType === '' || $payProduct === '') {
            throw new PaymentDefinitiveException('支付单插件支付上下文不完整', 40200, [
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
}
