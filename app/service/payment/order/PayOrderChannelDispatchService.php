<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentType;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\PaymentPluginManager;
use Throwable;

/**
 * 支付渠道单据拉起服务。
 *
 * 负责调用第三方插件、写回渠道订单号，并在失败时推进支付失败状态。
 */
class PayOrderChannelDispatchService extends BaseService
{
    /**
     * 构造函数，注入对应依赖。
     */
    public function __construct(
        protected PaymentPluginManager $paymentPluginManager,
        protected PaymentTypeRepository $paymentTypeRepository,
        protected PayOrderRepository $payOrderRepository,
        protected PayOrderLifecycleService $payOrderLifecycleService
    ) {
    }

    /**
     * 拉起第三方支付单并回写渠道响应。
     *
     * @return array{pay_order:PayOrder,payment_result:array,pay_params:array}
     */
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel): array
    {
        try {
            $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $payOrder->pay_type_id);
            /** @var PaymentType|null $paymentType */
            $paymentType = $this->paymentTypeRepository->find((int) $payOrder->pay_type_id);
            $extJson = (array) ($payOrder->ext_json ?? []);
            $callbackBaseUrl = trim((string) ($extJson['channel_callback_base_url'] ?? ''));
            $callbackUrl = $callbackBaseUrl === ''
                ? ''
                : rtrim($callbackBaseUrl, '/') . '/' . $payOrder->pay_no . '/callback';

            $channelResult = $plugin->pay([
                'pay_no' => (string) $payOrder->pay_no,
                'order_id' => (string) $payOrder->pay_no,
                'biz_no' => (string) $payOrder->biz_no,
                'trace_no' => (string) $payOrder->trace_no,
                'channel_request_no' => (string) $payOrder->channel_request_no,
                'merchant_id' => (int) $payOrder->merchant_id,
                'merchant_no' => (string) ($extJson['merchant_no'] ?? ''),
                'pay_type_id' => (int) $payOrder->pay_type_id,
                'pay_type_code' => (string) ($paymentType->code ?? ''),
                'amount' => (int) $payOrder->pay_amount,
                'subject' => (string) ($bizOrder->subject ?? ''),
                'body' => (string) ($bizOrder->body ?? ''),
                'callback_url' => $callbackUrl,
                'return_url' => (string) ($extJson['return_url'] ?? ''),
                '_env' => (string) (($extJson['device'] ?? '') ?: 'pc'),
                'extra' => $extJson,
            ]);

            $payOrder = $this->transactionRetry(function () use ($payOrder, $channelResult) {
                $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
                if (!$latest) {
                    throw new ResourceNotFoundException('支付单不存在', ['pay_no' => (string) $payOrder->pay_no]);
                }

                $latest->channel_order_no = (string) ($channelResult['chan_order_no'] ?? $latest->channel_order_no ?? '');
                $latest->channel_trade_no = (string) ($channelResult['chan_trade_no'] ?? $latest->channel_trade_no ?? '');
                $latest->ext_json = array_merge((array) $latest->ext_json, [
                    'pay_params_type' => (string) (($channelResult['pay_params']['type'] ?? '') ?: ''),
                    'pay_product' => (string) ($channelResult['pay_product'] ?? ''),
                    'pay_action' => (string) ($channelResult['pay_action'] ?? ''),
                    'pay_params_snapshot' => $this->normalizePayParamsSnapshot($channelResult['pay_params'] ?? []),
                ]);
                $latest->save();

                return $latest->refresh();
            });
        } catch (PaymentException $e) {
            $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, [
                'channel_error_msg' => $e->getMessage(),
                'channel_error_code' => (string) $e->getCode(),
                'ext_json' => [
                    'plugin_code' => (string) $payOrder->plugin_code,
                ],
            ]);

            throw $e;
        } catch (Throwable $e) {
            $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, [
                'channel_error_msg' => $e->getMessage(),
                'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                'ext_json' => [
                    'plugin_code' => (string) $payOrder->plugin_code,
                ],
            ]);

            throw new PaymentException('创建第三方支付订单失败：' . $e->getMessage(), 40215);
        }

        return [
            'pay_order' => $payOrder,
            'payment_result' => $channelResult,
            'pay_params' => $channelResult['pay_params'] ?? [],
        ];
    }

    /**
     * 归一化支付参数快照，便于后续页面渲染和排障。
     */
    private function normalizePayParamsSnapshot(mixed $payParams): array
    {
        if (is_array($payParams)) {
            return $payParams;
        }

        if (is_object($payParams) && method_exists($payParams, 'toArray')) {
            $data = $payParams->toArray();
            return is_array($data) ? $data : [];
        }

        return [];
    }
}
