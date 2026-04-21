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
 *
 * @property PaymentPluginManager $paymentPluginManager 支付插件管理器
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 * @property PayOrderRepository $payOrderRepository 支付单仓库
 * @property PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
 */
class PayOrderChannelDispatchService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginManager $paymentPluginManager 支付插件管理器
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @param PayOrderRepository $payOrderRepository 支付单仓库
     * @param PayOrderLifecycleService $payOrderLifecycleService 支付单生命周期服务
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
     * @param PayOrder $payOrder 支付订单
     * @param BizOrder $bizOrder 业务订单
     * @param PaymentChannel $channel 渠道
     * @return array 拉起结果
     * @throws ResourceNotFoundException
     * @throws PaymentException
     */
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel): array
    {
        try {
            // 先构造支付插件实例，由插件完成具体渠道下单。
            $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $payOrder->pay_type_id);
            /** @var PaymentType|null $paymentType */
            $paymentType = $this->paymentTypeRepository->find((int) $payOrder->pay_type_id);
            $extJson = (array) ($payOrder->ext_json ?? []);
            // 下单回调基址由支付单提前写入，这里拼出具体支付单回调地址交给插件使用。
            $callbackBaseUrl = trim((string) ($extJson['channel_callback_base_url'] ?? ''));
            $callbackUrl = $callbackBaseUrl === ''
                ? ''
                : rtrim($callbackBaseUrl, '/') . '/' . $payOrder->pay_no . '/callback';

            // 插件下单参数里同时带业务单号、支付单号和扩展信息，方便渠道侧回调后能反查同一笔单。
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
                // 回写渠道订单号和支付参数快照，便于后续查询和回调排障。
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
            // 插件层异常统一收口为支付失败，避免订单长时间停留在处理中。
            $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, [
                'channel_error_msg' => $e->getMessage(),
                'channel_error_code' => (string) $e->getCode(),
                'ext_json' => [
                    'plugin_code' => (string) $payOrder->plugin_code,
                ],
            ]);

            throw $e;
        } catch (Throwable $e) {
            // 非业务异常同样收口为失败态，并保留原始错误信息。
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
     *
     * @param array|object|null $payParams 支付参数数组或对象
     * @return array<string, mixed> 参数快照
     */
    private function normalizePayParamsSnapshot(mixed $payParams): array
    {
        if (is_array($payParams)) {
            return $payParams;
        }

        if (is_object($payParams) && method_exists($payParams, 'toArray')) {
            // 有些插件会返回对象，这里统一转成数组，方便后续落库和页面回显。
            $data = $payParams->toArray();
            return is_array($data) ? $data : [];
        }

        return [];
    }
}





