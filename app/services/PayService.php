<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\NotFoundException;
use app\models\PaymentOrder;
use app\repositories\{PaymentMethodRepository, PaymentOrderRepository};
use app\common\contracts\AbstractPayPlugin;
use support\Request;

/**
 * 支付服务
 *
 * 负责聚合支付流程：通道路由、插件调用、订单更新等
 */
class PayService extends BaseService
{
    public function __construct(
        protected PayOrderService $payOrderService,
        protected ChannelRouterService $channelRouterService,
        protected PaymentOrderRepository $orderRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PluginService $pluginService,
    ) {}

    /**
     * 统一支付：创建订单（含幂等）、选择通道、调用插件统一下单
     *
     * @param array $orderData 内部订单数据
     *   - mch_id, app_id, mch_no, method_code, amount, subject, body, client_ip, extra...
     * @param array $options  额外选项
     *   - device: 设备类型（pc/mobile/wechat/alipay/qq/jump）
     *   - request: Request 对象（用于从 UA 检测环境）
     * @return array
     *   - order_id
     *   - mch_no
     *   - pay_params
     */
    public function unifiedPay(array $orderData, array $options = []): array
    {
        // 1. 创建订单（幂等）
        /** @var PaymentOrder $order */
        $order = $this->payOrderService->createOrder($orderData);

        // 2. 查询支付方式
        $method = $this->methodRepository->find($order->method_id);
        if (!$method) {
            throw new NotFoundException('支付方式不存在');
        }

        // 3. 通道路由
        $channel = $this->channelRouterService->chooseChannel(
            $order->merchant_id,
            $order->merchant_app_id,
            $order->method_id
        );

        // 4. 实例化插件并初始化（通过插件服务）
        $plugin = $this->pluginService->getPluginInstance($channel->plugin_code);

        $channelConfig = array_merge(
            $channel->getConfigArray(),
            ['enabled_products' => $channel->getEnabledProducts()]
        );
        $plugin->init($method->method_code, $channelConfig);

        // 5. 环境检测
        $device = $options['device'] ?? '';
        /** @var Request|null $request */
        $request = $options['request'] ?? null;

        if ($device) {
            $env = $this->mapDeviceToEnv($device);
        } elseif ($request instanceof Request) {
            $env = $this->detectEnvironment($request);
        } else {
            $env = AbstractPayPlugin::ENV_PC;
        }

        // 6. 调用插件统一下单
        $pluginOrderData = [
            'order_id' => $order->order_id,
            'mch_no' => $order->mch_order_no,
            'amount' => $order->amount,
            'subject' => $order->subject,
            'body' => $order->body,
        ];

        $payResult = $plugin->unifiedOrder($pluginOrderData, $channelConfig, $env);

        // 7. 计算实际支付金额（扣除手续费）
        $fee = $order->fee > 0 ? $order->fee : ($order->amount * ($channel->chan_cost / 100));
        $realAmount = $order->amount - $fee;

        // 8. 更新订单（通道、支付参数、实际金额）
        $extra = $order->extra ?? [];
        $extra['pay_params'] = $payResult['pay_params'] ?? null;
        $chanOrderNo = $payResult['chan_order_no'] ?? $payResult['channel_order_no'] ?? '';
        $chanTradeNo = $payResult['chan_trade_no'] ?? $payResult['channel_trade_no'] ?? '';

        $this->orderRepository->updateById($order->id, [
            'channel_id' => $channel->id,
            'chan_order_no' => $chanOrderNo,
            'chan_trade_no' => $chanTradeNo,
            'real_amount' => $realAmount,
            'fee' => $fee,
            'extra' => $extra,
        ]);

        return [
            'order_id' => $order->order_id,
            'mch_no' => $order->mch_order_no,
            'pay_params' => $payResult['pay_params'] ?? null,
        ];
    }

    /**
     * 根据请求 UA 检测环境
     */
    private function detectEnvironment(Request $request): string
    {
        $ua = strtolower($request->header('User-Agent', ''));

        if (strpos($ua, 'alipayclient') !== false) {
            return AbstractPayPlugin::ENV_ALIPAY_CLIENT;
        }

        if (strpos($ua, 'micromessenger') !== false) {
            return AbstractPayPlugin::ENV_WECHAT;
        }

        $mobileKeywords = ['mobile', 'android', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone'];
        foreach ($mobileKeywords as $keyword) {
            if (strpos($ua, $keyword) !== false) {
                return AbstractPayPlugin::ENV_H5;
            }
        }

        return AbstractPayPlugin::ENV_PC;
    }

    /**
     * 映射设备类型到环境代码
     */
    private function mapDeviceToEnv(string $device): string
    {
        $mapping = [
            'pc'     => AbstractPayPlugin::ENV_PC,
            'mobile' => AbstractPayPlugin::ENV_H5,
            'qq'     => AbstractPayPlugin::ENV_H5,
            'wechat' => AbstractPayPlugin::ENV_WECHAT,
            'alipay' => AbstractPayPlugin::ENV_ALIPAY_CLIENT,
            'jump'   => AbstractPayPlugin::ENV_PC,
        ];

        return $mapping[strtolower($device)] ?? AbstractPayPlugin::ENV_PC;
    }
}


