<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\PaymentPluginManager;
use support\Log;
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
    ) {}

    /**
     * 拉起第三方支付单并回写渠道响应。
     *
     * @param PayOrder $payOrder 支付订单
     * @param BizOrder $bizOrder 业务订单
     * @param PaymentChannel $channel 渠道
     * @param Merchant $merchant 商户
     * @return array 拉起结果
     * @throws ResourceNotFoundException
     * @throws PaymentException
     */
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, Merchant $merchant): array
    {
        $pluginPayPayload = $this->buildPluginPayPayload($payOrder, $bizOrder, $merchant);
        $pluginPayResult = $this->callPluginPay($payOrder, $channel, $pluginPayPayload);
        $payOrder = $this->persistPluginPayResult($payOrder, $pluginPayResult);

        return [
            'pay_order' => $payOrder,
            'payment_result' => $pluginPayResult,
            'pay_params' => $pluginPayResult['pay_params'],
        ];
    }

    /**
     * 调用插件下单并校验返回结构。
     *
     * 插件创建、插件 pay() 和返回结构校验失败，都属于通道拉起失败，需要推进支付失败。
     *
     * @param PayOrder $payOrder 支付单
     * @param PaymentChannel $channel 支付通道
     * @param array<string, mixed> $payload 插件下单参数
     * @return array<string, mixed>
     * @throws PaymentException
     */
    private function callPluginPay(PayOrder $payOrder, PaymentChannel $channel, array $payload): array
    {
        try {
            $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $payOrder->pay_type_id);
            $result = $plugin->pay($payload);

            return PaymentPluginPayResultValidator::make($result)
                ->withScene('pay_result')
                ->withException(PaymentException::class)
                ->validate();
        } catch (PaymentException $e) {
            // Validator 默认给 400，这里统一为支付通道错误码；插件主动抛出的业务码保持原样。
            $exception = (int) $e->getCode() === 400 ? new PaymentException($e->getMessage(), 40200, $e->getData()) : $e;
            throw $this->recordPluginPayFailure($payOrder, $exception);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 插件下单异常 pay_no=%s channel_id=%d exception=%s error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                get_class($e),
                $e->getMessage()
            ));
            $exception = new PaymentException('创建第三方支付订单失败', 40200, [
                'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                'exception_class' => get_class($e),
            ]);
            throw $this->recordPluginPayFailure($payOrder, $exception);
        }
    }

    /**
     * 构建插件下单参数。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder $bizOrder 业务单
     * @param Merchant $merchant 商户
     * @return array<string, mixed>
     */
    private function buildPluginPayPayload(PayOrder $payOrder, BizOrder $bizOrder, Merchant $merchant): array
    {
        $paymentType = $this->paymentTypeRepository->find((int) $payOrder->pay_type_id);

        return [
            'pay_no' => $payOrder->pay_no,
            'order_id' => $payOrder->pay_no,
            'biz_no' => $payOrder->biz_no,
            'trace_no' => $payOrder->trace_no,
            'channel_request_no' => $payOrder->channel_request_no,
            'merchant_id' => (int) $payOrder->merchant_id,
            'merchant_no' => $merchant->merchant_no,
            'pay_type_id' => (int) $payOrder->pay_type_id,
            'pay_type_code' => $paymentType->code,
            'amount' => (int) $payOrder->pay_amount,
            'subject' => $bizOrder->subject,
            'body' => $bizOrder->body,
            'callback_url' => rtrim(sys_config('site_url'), '/') . '/api/pay/' . $payOrder->pay_no . '/callback',
            'notify_url' => $payOrder->notify_url,
            'return_url' => $this->resolveReturnUrl($payOrder, $bizOrder),
            'client_ip' => $payOrder->client_ip,
            '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
            'extra' => (array) ($payOrder->ext_json ?? []),
        ];
    }

    /**
     * 解析传给支付插件的同步跳转地址。
     *
     * 页面跳转支付已要求商户传 return_url；API 下单允许为空，这里兜底为平台支付承接页，
     * 避免上游 ePay 类插件收到空同步地址。
     *
     * @param PayOrder $payOrder 支付单
     * @param BizOrder $bizOrder 业务单
     * @return string 同步跳转地址
     */
    private function resolveReturnUrl(PayOrder $payOrder, BizOrder $bizOrder): string
    {
        $returnUrl = trim((string) ($payOrder->return_url ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        $returnUrl = trim((string) ($bizOrder->return_url ?? ''));
        if ($returnUrl !== '') {
            return $returnUrl;
        }

        $siteUrl = rtrim((string) sys_config('site_url'), '/');
        $path = '/payment/' . rawurlencode((string) $payOrder->pay_no);

        return $siteUrl !== '' ? $siteUrl . $path : $path;
    }

    /**
     * 回写渠道成功返回的支付承接参数。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $pluginPayResult 插件支付结果
     * @return PayOrder 支付单
     */
    private function persistPluginPayResult(PayOrder $payOrder, array $pluginPayResult): PayOrder
    {
        return $this->transactionRetry(function () use ($payOrder, $pluginPayResult) {
            // 回写渠道订单号和支付承接页结果，便于后续查询和页面渲染。
            $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
            if (!$latest) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => (string) $payOrder->pay_no]);
            }

            $latest->channel_order_no = (string) ($pluginPayResult['chan_order_no'] ?? $latest->channel_order_no ?? '');
            $latest->channel_trade_no = (string) ($pluginPayResult['chan_trade_no'] ?? $latest->channel_trade_no ?? '');
            $extJson = (array) $latest->ext_json;
            $extJson['presentation'] = [
                'pay_page' => $pluginPayResult['pay_page'],
                'pay_type' => $pluginPayResult['pay_type'],
                'pay_product' => $pluginPayResult['pay_product'],
                'pay_action' => $pluginPayResult['pay_action'],
                'pay_params' => $pluginPayResult['pay_params'],
            ];
            $latest->ext_json = $extJson;
            $latest->save();

            return $latest->refresh();
        });
    }

    /**
     * 记录插件下单失败，并返回带支付单号的异常。
     *
     * @param PayOrder $payOrder 支付单
     * @param PaymentException $e 支付异常
     * @return PaymentException 可继续抛给入口层的异常
     */
    private function recordPluginPayFailure(PayOrder $payOrder, PaymentException $e): PaymentException
    {
        $data = $e->getData();
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
        $message = $message !== '' ? $message : '支付通道返回异常';
        $code = (string) ($data['channel_error_code'] ?? ($e->getCode() ?: 'PLUGIN_PAY_FAILED'));

        $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, [
            'channel_error_msg' => $message,
            'channel_error_code' => $code,
            'ext_json' => [
                'presentation' => [
                    'pay_page' => 'error',
                    'pay_type' => '',
                    'pay_product' => 'error',
                    'pay_action' => 'error',
                    'pay_params' => [
                        'error_msg' => $message,
                        'code' => $code,
                        'pay_no' => (string) $payOrder->pay_no,
                    ],
                ],
            ],
        ]);

        $data['pay_no'] = (string) $payOrder->pay_no;

        return new PaymentException(
            $message,
            (int) ($e->getCode() ?: 40200),
            $data
        );
    }
}
