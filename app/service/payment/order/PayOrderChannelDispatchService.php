<?php

namespace app\service\payment\order;

use app\common\base\BasePayment;
use app\common\base\BaseService;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\TradeConstant;
use app\exception\PaymentDefinitiveException;
use app\exception\BusinessStateException;
use app\exception\PaymentException;
use app\exception\PaymentProductUnavailableException;
use app\exception\PaymentUncertainException;
use app\exception\ResourceNotFoundException;
use app\exception\UnsupportedPaymentOperationException;
use app\model\merchant\Merchant;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\repository\payment\config\PaymentTypeRepository;
use app\repository\payment\trade\PayOrderRepository;
use app\service\payment\runtime\PaymentPluginManager;
use support\Log;
use Throwable;
use Webman\Exception\BusinessException;

/**
 * 支付渠道单据拉起服务。
 *
 * 负责调用第三方插件、校验标准结果并推进同步支付状态。
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
     * 入参支付单已提交。整个派发出口保留原支付单号；结果写回失败不代表上游未收单，
     * 不在异常出口重新下单、改失败状态或释放服务费。
     *
     * @param PayOrder $payOrder 支付订单
     * @param BizOrder $bizOrder 业务订单
     * @param PaymentChannel $channel 渠道
     * @param Merchant $merchant 商户
     * @return array 拉起结果
     * @throws BusinessException 业务异常保留原类别，并附带当前 pay_no
     * @throws PaymentUncertainException 未知处理异常，保留订单供回调、查单或人工核实
     */
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, Merchant $merchant): array
    {
        try {
            $pluginPayPayload = $this->buildPluginPayPayload($payOrder, $bizOrder, $merchant);
            $pluginPayResult = $this->callPluginPay($payOrder, $channel, $pluginPayPayload);
            $payOrder = $this->applyPluginPayResult($payOrder, $pluginPayResult);
            $presentation = (array) ($pluginPayResult['presentation'] ?? []);

            return [
                'pay_order' => $payOrder,
                'payment_result' => $pluginPayResult,
                'pay_params' => (array) ($presentation['pay_params'] ?? []),
            ];
        } catch (BusinessException $e) {
            $e->data(array_replace($e->getData(), ['pay_no' => (string) $payOrder->pay_no]));
            throw $e;
        } catch (Throwable $e) {
            // 包含失败记录本身写入失败的情况，不能再写库掩盖原故障或继续候选通道。
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 支付处理失败 pay_no=%s channel_id=%d exception=%s error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                $e::class,
                $e->getMessage()
            ));
            throw new PaymentUncertainException('支付处理异常，结果尚未确认，请查询原支付订单', 40200, [
                'pay_no' => (string) $payOrder->pay_no,
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * 调用插件下单并校验返回结构。
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
        } catch (PaymentException $e) {
            $exception = $e instanceof PaymentDefinitiveException || $e instanceof UnsupportedPaymentOperationException
                ? $e
                : new PaymentDefinitiveException(
                    $e->getMessage(),
                    (int) ($e->getCode() ?: 40200),
                    $e->getData()
                );
            throw $this->recordPluginPayFailure($payOrder, $exception);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 插件初始化失败 pay_no=%s channel_id=%d exception=%s error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                get_class($e),
                $e->getMessage()
            ));
            throw $this->recordPluginPayFailure($payOrder, new PaymentDefinitiveException(
                '支付插件初始化失败',
                40200,
                [
                    'channel_error_code' => 'PLUGIN_INITIALIZE_ERROR',
                    'exception_class' => get_class($e),
                ]
            ));
        }

        try {
            $this->persistProvisionalPaymentContext($payOrder, $payload);
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 支付外呼上下文预写失败 pay_no=%s channel_id=%d error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                $e->getMessage()
            ));
            throw $this->recordPluginPayFailure($payOrder, new PaymentDefinitiveException(
                '支付请求准备失败，请稍后重试',
                40200,
                ['channel_error_code' => 'PAYMENT_CONTEXT_PERSIST_FAILED']
            ));
        }

        try {
            $preparedProduct = '';
            $result = $plugin instanceof BasePayment
                ? $plugin->payWithProductPreflight($payload, function (string $product) use ($payOrder, $payload, &$preparedProduct): void {
                    $this->persistProvisionalPaymentContext($payOrder, $payload, $product);
                    $preparedProduct = $product;
                })
                : $plugin->pay($payload);

            $validated = PaymentPluginPayResultValidator::make($result)
                ->withScene('pay_result')
                ->withException(PaymentException::class)
                ->validate();

            if (!hash_equals((string) $payOrder->pay_no, (string) $validated['pay_no'])) {
                throw new PaymentUncertainException('插件下单返回的支付单号不匹配', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                ]);
            }
            if ($preparedProduct !== '' && !hash_equals($preparedProduct, (string) $validated['pay_product'])) {
                throw new PaymentUncertainException('插件返回产品与外呼前保存的产品不一致', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                    'pay_product' => $preparedProduct,
                ]);
            }
            $extra = (array) ($payload['extra'] ?? []);
            $payment = (array) ($extra['payment'] ?? []);
            $selectedProduct = trim((string) ($payment['selected_product'] ?? ''));
            if ($selectedProduct !== '' && !hash_equals($selectedProduct, (string) $validated['pay_product'])) {
                throw new PaymentUncertainException('插件下单返回产品与身份授权产品不一致', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                    'selected_product' => $selectedProduct,
                    'actual_product' => (string) $validated['pay_product'],
                ]);
            }
            if ((string) $validated['status'] === PaymentPluginStatusConstant::SUCCESS
                && (int) $validated['paid_amount'] !== (int) $payOrder->pay_amount) {
                throw new PaymentUncertainException('插件下单返回的实付金额不匹配', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                ]);
            }
            if ((string) $validated['status'] === PaymentPluginStatusConstant::SUCCESS
                && trim((string) ($validated['chan_order_no'] ?? '')) === ''
                && trim((string) ($validated['chan_trade_no'] ?? '')) === '') {
                throw new PaymentUncertainException('插件下单成功结果缺少渠道流水号', 40200, [
                    'pay_no' => (string) $payOrder->pay_no,
                ]);
            }

            return $validated;
        } catch (PaymentDefinitiveException|UnsupportedPaymentOperationException $e) {
            if ($preparedProduct !== '' && $e instanceof PaymentProductUnavailableException) {
                // 已进入选定产品的 handler；不能仅凭上游“产品不可用”推断请求从未送达。
                $uncertain = new PaymentUncertainException($e->getMessage(), (int) ($e->getCode() ?: 40200),
                    array_replace($e->getData(), ['pay_product' => $preparedProduct]));
                $this->persistUncertainPaymentContext($payOrder, $uncertain);
                throw $uncertain;
            }
            throw $this->recordPluginPayFailure($payOrder, $e);
        } catch (PaymentUncertainException $e) {
            // 插件已包装未知异常并附带产品；诊断仍由上层记录，不在插件重复写日志。
            if (isset($e->getData()['exception_class'])) {
                Log::warning(sprintf(
                    '[PayOrderChannelDispatchService] 插件下单异常 pay_no=%s channel_id=%d exception=%s error=%s',
                    (string) $payOrder->pay_no,
                    (int) $channel->id,
                    (string) $e->getData()['exception_class'],
                    $e->getMessage()
                ));
            }
            $this->persistUncertainPaymentContext($payOrder, $e);
            throw $e;
        } catch (PaymentException $e) {
            $exception = new PaymentUncertainException(
                $e->getMessage(),
                (int) ($e->getCode() ?: 40200),
                $e->getData()
            );
            $this->persistUncertainPaymentContext($payOrder, $exception);
            throw $exception;
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 插件下单异常 pay_no=%s channel_id=%d exception=%s error=%s',
                (string) $payOrder->pay_no,
                (int) $channel->id,
                get_class($e),
                $e->getMessage()
            ));
            $exception = new PaymentUncertainException('创建第三方支付订单结果不确定', 40200, [
                'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                'exception_class' => get_class($e),
            ]);
            throw $exception;
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
     * 按插件标准结果写回渠道信息并推进同步成功状态。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $pluginPayResult 插件支付结果
     * @return PayOrder 支付单
     */
    private function applyPluginPayResult(PayOrder $payOrder, array $pluginPayResult): PayOrder
    {
        $input = [
            'channel_order_no' => (string) ($pluginPayResult['chan_order_no'] ?? ''),
            'channel_trade_no' => (string) ($pluginPayResult['chan_trade_no'] ?? ''),
            'ext_json' => ['payment_context' => $this->paymentContext($pluginPayResult)],
        ];
        if ((string) $pluginPayResult['status'] === PaymentPluginStatusConstant::SUCCESS) {
            return $this->payOrderLifecycleService->markPaySuccess((string) $payOrder->pay_no, $input);
        }

        $input['ext_json']['presentation'] = $pluginPayResult['presentation'];
        return $this->payOrderLifecycleService->recordPayProgress((string) $payOrder->pay_no, $input);
    }

    /**
     * 提取插件明确返回的支付产品上下文。
     *
     * @param array<string, mixed> $pluginPayResult 标准支付结果
     * @return array<string, mixed>
     */
    private function paymentContext(array $pluginPayResult): array
    {
        return [
            'pay_type' => (string) $pluginPayResult['pay_type'],
            'pay_product' => (string) $pluginPayResult['pay_product'],
            'pay_action' => (string) ($pluginPayResult['pay_action'] ?? ''),
            'channel_context' => (array) ($pluginPayResult['channel_context'] ?? []),
        ];
    }

    /**
     * 在调用上游前持久化最小查单上下文。
     *
     * 外呼发生超时、断连或响应解析失败时，正常支付结果不会返回；预写快照保证
     * 运行时保留原支付方式；插件选择具体产品后再次调用并提交事务，
     * 才允许执行产品处理器。核心不按环境猜测真实产品。
     *
     * @param PayOrder $payOrder 支付单
     * @param array<string, mixed> $payload 插件下单参数
     * @param string $preparedProduct 插件在执行产品处理器前确定的产品
     * @return void
     * @throws BusinessStateException 请求支付方式或身份固定产品与已存上下文冲突
     */
    private function persistProvisionalPaymentContext(PayOrder $payOrder, array $payload, string $preparedProduct = ''): void
    {
        $this->transactionRetry(function () use ($payOrder, $payload, $preparedProduct): void {
            $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
            if (!$latest) {
                throw new ResourceNotFoundException('支付单不存在', ['pay_no' => (string) $payOrder->pay_no]);
            }
            if ($preparedProduct !== '' && !in_array((int) $latest->status, TradeConstant::orderMutableStatuses(), true)) {
                throw new BusinessStateException('支付单已结束，不得再次请求支付通道', ['pay_no' => (string) $latest->pay_no]);
            }

            $extJson = (array) ($latest->ext_json ?? []);
            $existing = (array) ($extJson['payment_context'] ?? []);
            $payType = (string) $payload['pay_type_code'];
            $selectedProduct = (string) ($payload['extra']['payment']['selected_product'] ?? '');
            if ($selectedProduct !== '' && $preparedProduct !== '' && !hash_equals($selectedProduct, $preparedProduct)) {
                throw new BusinessStateException('身份授权产品与插件预选产品不一致', ['pay_no' => (string) $latest->pay_no]);
            }
            $selectedProduct = $preparedProduct !== '' ? $preparedProduct : $selectedProduct;
            $existingType = (string) ($existing['pay_type'] ?? '');
            $existingProduct = (string) ($existing['pay_product'] ?? '');
            if (($existingType !== '' && !hash_equals($existingType, $payType))
                || ($selectedProduct !== '' && $existingProduct !== '' && !hash_equals($existingProduct, $selectedProduct))) {
                throw new BusinessStateException('支付请求产品与已保存上下文不一致', ['pay_no' => (string) $latest->pay_no]);
            }
            if ($existingType !== '' && $existingProduct !== '') {
                return;
            }

            $extJson['payment_context'] = array_merge($existing, [
                'pay_type' => $payType,
                'pay_product' => $selectedProduct !== '' ? $selectedProduct : $existingProduct,
                'pay_action' => (string) ($existing['pay_action'] ?? ''),
                'channel_context' => (array) ($existing['channel_context'] ?? []),
                '_provisional' => true,
                '_prepared_at' => $this->now(),
            ]);
            $latest->ext_json = $extJson;
            $latest->save();
        });
    }

    /**
     * 将插件在外呼前已经确定的产品写回不确定态上下文。
     *
     * 只补暂存上下文；已确认事实及非空参数不被迟到异常降级或擦除。
     * 冲突或保存失败沿用诊断日志，调用方仍抛原不确定异常，不能据此判定支付失败。
     *
     * @param PayOrder $payOrder 支付单
     * @param PaymentException $exception 不确定异常
     * @return void
     */
    private function persistUncertainPaymentContext(PayOrder $payOrder, PaymentException $exception): void
    {
        $data = $exception->getData();
        $payProduct = (string) ($data['pay_product'] ?? '');
        if ($payProduct === '') {
            return;
        }

        try {
            $this->transactionRetry(function () use ($payOrder, $payProduct, $data): void {
                $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
                if (!$latest) {
                    return;
                }

                $extJson = (array) ($latest->ext_json ?? []);
                $context = (array) ($extJson['payment_context'] ?? []);
                $payType = (string) (($data['pay_type'] ?? '') !== '' ? $data['pay_type'] : ($context['pay_type'] ?? ''));
                $existingType = (string) ($context['pay_type'] ?? '');
                $existingProduct = (string) ($context['pay_product'] ?? '');
                if (($existingType !== '' && $payType !== '' && !hash_equals($existingType, $payType))
                    || ($existingProduct !== '' && !hash_equals($existingProduct, $payProduct))) {
                    throw new BusinessStateException('支付不确定结果与已保存产品上下文不一致', ['pay_no' => (string) $latest->pay_no]);
                }
                if ($existingType !== '' && $existingProduct !== '' && empty($context['_provisional'])) {
                    return;
                }

                $context['pay_type'] = $payType;
                $context['pay_product'] = $payProduct;
                $context['pay_action'] = (string) ($context['pay_action'] ?? '');
                if ($context['pay_action'] === '') {
                    $context['pay_action'] = (string) ($data['pay_action'] ?? '');
                }
                $context['channel_context'] = (array) (($context['channel_context'] ?? []) ?: ($data['channel_context'] ?? []));
                $context['_provisional'] = true;
                $context['_uncertain_at'] = $this->now();
                $extJson['payment_context'] = $context;
                $latest->ext_json = $extJson;
                $latest->save();
            });
        } catch (Throwable $e) {
            Log::warning(sprintf(
                '[PayOrderChannelDispatchService] 支付不确定态产品上下文写入失败 pay_no=%s error=%s',
                (string) $payOrder->pay_no,
                $e->getMessage()
            ));
        }
    }

    /**
     * 记录插件明确拒绝，并返回原异常。
     *
     * 失败状态和服务费释放由生命周期同事务完成；写入失败向上抛出，不能继续通道兜底。
     * pay_no 统一由 dispatch() 异常出口附加，不重新构造业务异常。
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
        ]);

        return $e;
    }
}
