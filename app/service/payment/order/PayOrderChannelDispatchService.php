<?php

namespace app\service\payment\order;

use app\common\base\BaseService;
use app\exception\PaymentException;
use app\exception\ResourceNotFoundException;
use app\model\merchant\Merchant;
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
     * @param Merchant $merchant 商户
     * @return array 拉起结果
     * @throws ResourceNotFoundException
     * @throws PaymentException
     */
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, Merchant $merchant): array
    {
        try {
            // 先构造支付插件实例，由插件完成具体渠道下单。
            $plugin = $this->paymentPluginManager->createByChannel($channel, (int) $payOrder->pay_type_id);
            /** @var PaymentType|null $paymentType */
            $paymentType = $this->paymentTypeRepository->find((int) $payOrder->pay_type_id);
            $extJson = (array) ($payOrder->ext_json ?? []);
            $callbackUrl = rtrim(sys_config('site_url'), '/') . '/api/pay/' . $payOrder->pay_no . '/callback';

            // 插件下单参数里同时带业务单号、支付单号和结构化扩展信息，方便渠道侧回调后能反查同一笔单。
            $channelResult = $this->validatePluginPayResult($plugin->pay([
                'pay_no' => (string) $payOrder->pay_no,
                'order_id' => (string) $payOrder->pay_no,
                'biz_no' => (string) $payOrder->biz_no,
                'trace_no' => (string) $payOrder->trace_no,
                'channel_request_no' => (string) $payOrder->channel_request_no,
                'merchant_id' => (int) $payOrder->merchant_id,
                'merchant_no' => (string) $merchant->merchant_no,
                'pay_type_id' => (int) $payOrder->pay_type_id,
                'pay_type_code' => (string) ($paymentType->code ?? ''),
                'amount' => (int) $payOrder->pay_amount,
                'subject' => (string) ($bizOrder->subject ?? ''),
                'body' => (string) ($bizOrder->body ?? ''),
                'callback_url' => $callbackUrl,
                'notify_url' => (string) ($payOrder->notify_url ?? ''),
                'return_url' => (string) ($payOrder->return_url ?? ''),
                'client_ip' => (string) ($payOrder->client_ip ?? ''),
                '_env' => (string) (($payOrder->device ?? '') ?: 'pc'),
                'extra' => $extJson,
            ]));

            $payOrder = $this->transactionRetry(function () use ($payOrder, $channelResult) {
                // 回写渠道订单号和支付参数快照，便于后续查询和回调排障。
                $latest = $this->payOrderRepository->findForUpdateByPayNo((string) $payOrder->pay_no);
                if (!$latest) {
                    throw new ResourceNotFoundException('支付单不存在', ['pay_no' => (string) $payOrder->pay_no]);
                }

                $latest->channel_order_no = (string) ($channelResult['chan_order_no'] ?? $latest->channel_order_no ?? '');
                $latest->channel_trade_no = (string) ($channelResult['chan_trade_no'] ?? $latest->channel_trade_no ?? '');
                $latest->ext_json = array_replace_recursive((array) $latest->ext_json, [
                    'presentation' => [
                        'params_type' => (string) $channelResult['pay_params']['type'],
                        'product' => (string) ($channelResult['pay_product'] ?? ''),
                        'action' => (string) ($channelResult['pay_action'] ?? ''),
                        'params_snapshot' => $channelResult['pay_params'],
                    ],
                    'plugin' => [
                        'pay_result' => (array) ($channelResult['ext_json'] ?? []),
                    ],
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
                    'plugin' => [
                        'code' => (string) $payOrder->plugin_code,
                    ],
                ],
            ]);

            throw $e;
        } catch (Throwable $e) {
            // 非业务异常同样收口为失败态，并保留原始错误信息。
            $this->payOrderLifecycleService->markPayFailed((string) $payOrder->pay_no, [
                'channel_error_msg' => $e->getMessage(),
                'channel_error_code' => 'PLUGIN_CREATE_ORDER_ERROR',
                'ext_json' => [
                    'plugin' => [
                        'code' => (string) $payOrder->plugin_code,
                    ],
                ],
            ]);

            throw new PaymentException('创建第三方支付订单失败', 40215, [
                'error' => $e->getMessage(),
                'plugin_code' => (string) $payOrder->plugin_code,
            ]);
        }

        return [
            'pay_order' => $payOrder,
            'payment_result' => $channelResult,
            'pay_params' => $channelResult['pay_params'],
        ];
    }

    /**
     * 校验并归一化插件下单返回值。
     *
     * 插件返回值是支付页承接的唯一来源，必须在这里变成明确、可落库、可渲染的结构。
     *
     * @param array<string, mixed> $result 插件下单返回值
     * @return array<string, mixed> 标准下单返回值
     * @throws PaymentException
     */
    private function validatePluginPayResult(array $result): array
    {
        foreach (['pay_product', 'pay_action', 'pay_params', 'chan_order_no'] as $key) {
            if (!array_key_exists($key, $result)) {
                throw new PaymentException('插件下单返回缺少标准字段', 40200, [
                    'missing_key' => $key,
                ]);
            }
        }

        $payProduct = strtolower(trim((string) $result['pay_product']));
        $payAction = strtolower(trim((string) $result['pay_action']));
        $channelOrderNo = trim((string) $result['chan_order_no']);
        $channelTradeNo = trim((string) ($result['chan_trade_no'] ?? ''));

        if ($payProduct === '') {
            throw new PaymentException('插件下单返回 pay_product 不能为空', 40200);
        }
        if ($payAction === '') {
            throw new PaymentException('插件下单返回 pay_action 不能为空', 40200);
        }
        if ($channelOrderNo === '') {
            throw new PaymentException('插件下单返回 chan_order_no 不能为空', 40200);
        }
        if (array_key_exists('ext_json', $result) && !is_array($result['ext_json'])) {
            throw new PaymentException('插件下单返回 ext_json 必须为数组', 40200);
        }

        $payParams = $this->normalizePayParamsSnapshot($result['pay_params']);
        $payParams = $this->validatePayParams($payParams);

        return [
            'pay_product' => $payProduct,
            'pay_action' => $payAction,
            'pay_params' => $payParams,
            'chan_order_no' => $channelOrderNo,
            'chan_trade_no' => $channelTradeNo,
            'ext_json' => (array) ($result['ext_json'] ?? []),
        ];
    }

    /**
     * 校验支付页承接参数。
     *
     * 每一种 `type` 都对应收银台的一种页面动作；必要载荷缺失时直接判定为插件异常。
     *
     * @param array<string, mixed> $payParams 支付参数
     * @return array<string, mixed>
     * @throws PaymentException
     */
    private function validatePayParams(array $payParams): array
    {
        $type = strtolower(trim((string) ($payParams['type'] ?? '')));
        if ($type === '') {
            throw new PaymentException('插件下单返回 pay_params.type 不能为空', 40200);
        }

        $aliases = [
            'scan' => 'qrcode',
            'qr' => 'qrcode',
            'code' => 'qrcode',
            'redirect' => 'jump',
            'url' => 'jump',
            'wap' => 'h5',
            'form' => 'html',
            'app' => 'urlscheme',
            'applet' => 'mini',
            'wxplugin' => 'mini',
        ];
        $type = $aliases[$type] ?? $type;

        $allowed = [
            'jump',
            'web',
            'h5',
            'qrcode',
            'html',
            'jsapi',
            'urlscheme',
            'mini',
            'pos',
            'transfer',
            'json',
            'error',
        ];
        if (!in_array($type, $allowed, true)) {
            throw new PaymentException('插件下单返回 pay_params.type 不支持', 40200, [
                'type' => $type,
            ]);
        }

        $payParams['type'] = $type;

        if (in_array($type, ['jump', 'web', 'h5'], true)) {
            $url = $this->firstText($payParams, ['redirect_url', 'payurl', 'pay_url', 'mweb_url', 'url']);
            if ($url === '') {
                throw new PaymentException('插件跳转支付缺少支付链接', 40200, [
                    'type' => $type,
                ]);
            }
            $payParams['redirect_url'] = $url;
            $payParams['payurl'] = $url;
        }

        if ($type === 'qrcode') {
            $qrcode = $this->firstText($payParams, ['qrcode_text', 'qrcode_data', 'qrcode_url', 'qrcode']);
            if ($qrcode === '') {
                throw new PaymentException('插件二维码支付缺少二维码内容', 40200);
            }
            $payParams['qrcode_text'] = $qrcode;
            $payParams['qrcode'] = $qrcode;
        }

        if ($type === 'html' && $this->firstText($payParams, ['html', 'action']) === '') {
            throw new PaymentException('插件表单支付缺少 html 或 action', 40200);
        }

        if ($type === 'urlscheme') {
            $urlscheme = $this->firstText($payParams, ['urlscheme', 'redirect_url', 'order_str', 'order_string']);
            if ($urlscheme === '') {
                throw new PaymentException('插件 URL Scheme 支付缺少唤起参数', 40200);
            }
            $payParams['urlscheme'] = $urlscheme;
            $payParams['redirect_url'] = $urlscheme;
        }

        if ($type === 'jsapi' && $this->firstText($payParams, ['order_str', 'order_string', 'app_id', 'appId']) === '' && empty($payParams['jsapi_params'])) {
            throw new PaymentException('插件 JSAPI 支付缺少拉起参数', 40200);
        }

        if ($type === 'mini' && $this->firstText($payParams, ['path', 'scheme', 'urlscheme', 'trade_no']) === '' && empty($payParams['mini_params'])) {
            throw new PaymentException('插件小程序支付缺少跳转参数', 40200);
        }

        if ($type === 'error' && $this->firstText($payParams, ['message', 'msg', 'error']) === '') {
            throw new PaymentException('插件错误支付结果缺少错误信息', 40200);
        }

        return $payParams;
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

    /**
     * 从候选字段中取首个非空文本。
     *
     * @param array<string, mixed> $data 数据
     * @param array<int, string> $keys 候选字段
     * @return string
     */
    private function firstText(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }
}


