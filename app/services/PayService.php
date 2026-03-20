<?php

namespace app\services;

use app\common\base\BaseService;
use app\common\contracts\PayPluginInterface;
use app\exceptions\NotFoundException;
use app\models\PaymentOrder;
use app\repositories\{PaymentMethodRepository, PaymentOrderRepository};
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
    public function pay(array $orderData, array $options = []): array
    {
        // 1. 创建订单（幂等）
        /** @var PaymentOrder $order */
        $order = $this->payOrderService->createOrder($orderData);
        $extra = $order->extra ?? [];

        // 2. 查询支付方式
        $method = $this->methodRepository->find($order->method_id);
        if (!$method) {
            throw new NotFoundException('支付方式不存在');
        }

        // 3. 通道路由
        try {
            $routeDecision = $this->channelRouterService->chooseChannelWithDecision(
                (int)$order->merchant_id,
                (int)$order->merchant_app_id,
                (int)$order->method_id,
                (float)$order->amount
            );
        } catch (\Throwable $e) {
            $extra['route_error'] = [
                'message' => $e->getMessage(),
                'at' => date('Y-m-d H:i:s'),
            ];
            $this->orderRepository->updateById((int)$order->id, ['extra' => $extra]);
            throw $e;
        }

        /** @var \app\models\PaymentChannel $channel */
        $channel = $routeDecision['channel'];
        unset($extra['route_error']);
        $extra['routing'] = $this->buildRoutingSnapshot($routeDecision, $channel);
        $this->orderRepository->updateById((int)$order->id, [
            'channel_id' => (int)$channel->id,
            'extra' => $extra,
        ]);

        // 4. 实例化插件并初始化（通过插件服务）
        $plugin = $this->pluginService->getPluginInstance($channel->plugin_code);

        $channelConfig = array_merge(
            $channel->getConfigArray(),
            ['enabled_products' => $channel->getEnabledProducts()]
        );
        $plugin->init($channelConfig);

        // 5. 环境检测
        $device = $options['device'] ?? '';
        /** @var Request|null $request */
        $request = $options['request'] ?? null;

        if ($device) {
            $env = $this->mapDeviceToEnv($device);
        } elseif ($request instanceof Request) {
            $env = $this->detectEnvironment($request);
        } else {
            $env = 'pc';
        }

        // 6. 调用插件统一下单
        $pluginOrderData = [
            'order_id' => $order->order_id,
            'mch_no'   => $order->mch_order_no,
            'amount'   => $order->amount,
            'subject'  => $order->subject,
            'body'     => $order->body,
            'extra'    => $extra,
            '_env'     => $env,
        ];

        $payResult = $plugin->pay($pluginOrderData);

        // 7. 计算实际支付金额（扣除手续费）
        $amount = (float)$order->amount;
        $chanCost = (float)$channel->chan_cost;
        $fee = ((float)$order->fee) > 0 ? (float)$order->fee : round($amount * ($chanCost / 100), 2);
        $realAmount = round($amount - $fee, 2);

        // 8. 更新订单（通道、支付参数、实际金额）
        $extra['pay_params'] = $payResult['pay_params'] ?? null;
        $chanOrderNo = $payResult['chan_order_no'] ?? $payResult['channel_order_no'] ?? '';
        $chanTradeNo = $payResult['chan_trade_no'] ?? $payResult['channel_trade_no'] ?? '';

        $this->orderRepository->updateById($order->id, [
            'channel_id' => $channel->id,
            'chan_order_no' => $chanOrderNo,
            'chan_trade_no' => $chanTradeNo,
            'real_amount' => sprintf('%.2f', $realAmount),
            'fee' => sprintf('%.2f', $fee),
            'extra' => $extra,
        ]);

        return [
            'order_id' => $order->order_id,
            'mch_no' => $order->mch_order_no,
            'pay_params' => $payResult['pay_params'] ?? null,
        ];
    }

    private function buildRoutingSnapshot(array $routeDecision, \app\models\PaymentChannel $channel): array
    {
        $policy = is_array($routeDecision['policy'] ?? null) ? $routeDecision['policy'] : null;
        $candidates = [];
        foreach (($routeDecision['candidates'] ?? []) as $candidate) {
            $candidates[] = [
                'channel_id' => (int)($candidate['channel_id'] ?? 0),
                'chan_code' => (string)($candidate['chan_code'] ?? ''),
                'chan_name' => (string)($candidate['chan_name'] ?? ''),
                'available' => (bool)($candidate['available'] ?? false),
                'priority' => (int)($candidate['priority'] ?? 0),
                'weight' => (int)($candidate['weight'] ?? 0),
                'role' => (string)($candidate['role'] ?? ''),
                'reasons' => array_values($candidate['reasons'] ?? []),
            ];
        }

        return [
            'source' => (string)($routeDecision['source'] ?? 'fallback'),
            'route_mode' => (string)($routeDecision['route_mode'] ?? 'sort'),
            'policy' => $policy,
            'selected_channel_id' => (int)$channel->id,
            'selected_channel_code' => (string)$channel->chan_code,
            'selected_channel_name' => (string)$channel->chan_name,
            'candidates' => array_slice($candidates, 0, 10),
            'selected_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 根据请求 UA 检测环境
     */
    private function detectEnvironment(Request $request): string
    {
        $ua = strtolower($request->header('User-Agent', ''));

        if (strpos($ua, 'alipayclient') !== false) {
            return 'alipay';
        }

        if (strpos($ua, 'micromessenger') !== false) {
            return 'wechat';
        }

        $mobileKeywords = ['mobile', 'android', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone'];
        foreach ($mobileKeywords as $keyword) {
            if (strpos($ua, $keyword) !== false) {
                return 'h5';
            }
        }

        return 'pc';
    }

    /**
     * 映射设备类型到环境代码
     */
    private function mapDeviceToEnv(string $device): string
    {
        $mapping = [
            'pc'     => 'pc',
            'mobile' => 'h5',
            'qq'     => 'h5',
            'wechat' => 'wechat',
            'alipay' => 'alipay',
            'jump'   => 'pc',
        ];

        return $mapping[strtolower($device)] ?? 'pc';
    }
}


