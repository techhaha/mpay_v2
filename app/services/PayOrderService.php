<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\{BadRequestException, NotFoundException};
use app\models\PaymentOrder;
use app\repositories\{MerchantAppRepository, PaymentChannelRepository, PaymentMethodRepository, PaymentOrderRepository};

/**
 * 支付订单服务
 *
 * 负责订单创建、统一下单、状态管理等
 */
class PayOrderService extends BaseService
{
    public function __construct(
        protected MerchantAppRepository $merchantAppRepository,
        protected PaymentChannelRepository $channelRepository,
        protected PaymentOrderRepository $orderRepository,
        protected PaymentMethodRepository $methodRepository,
        protected PluginService $pluginService,
    ) {}

    /**
     * 创建订单
     */
    public function createOrder(array $data)
    {
        // 1. 基本参数校验
        $mchId = (int)($data['mch_id'] ?? $data['merchant_id'] ?? 0);
        $appId = (int)($data['app_id'] ?? 0);
        $mchNo = trim((string)($data['mch_no'] ?? $data['mch_order_no'] ?? ''));
        $methodCode = trim((string)($data['method_code'] ?? ''));
        $amount = (float)($data['amount'] ?? 0);
        $subject = trim((string)($data['subject'] ?? ''));

        if ($mchId <= 0 || $appId <= 0) {
            throw new BadRequestException('商户或应用信息不完整');
        }
        if ($mchNo === '') {
            throw new BadRequestException('商户订单号不能为空');
        }
        if ($methodCode === '') {
            throw new BadRequestException('支付方式不能为空');
        }
        if ($amount <= 0) {
            throw new BadRequestException('订单金额必须大于0');
        }
        if ($subject === '') {
            throw new BadRequestException('订单标题不能为空');
        }

        // 2. 查询支付方式ID
        $method = $this->methodRepository->findByCode($methodCode);
        if (!$method) {
            throw new BadRequestException('支付方式不存在');
        }

        // 3. 幂等校验：同一商户应用下相同商户订单号只保留一条
        $existing = $this->orderRepository->findByMchNo($mchId, $appId, $mchNo);
        if ($existing) {
            return $existing;
        }

        // 4. 生成系统订单号
        $orderId = $this->generateOrderId();

        // 5. 创建订单
        return $this->orderRepository->create([
            'order_id' => $orderId,
            'merchant_id' => $mchId,
            'merchant_app_id' => $appId,
            'mch_order_no' => $mchNo,
            'method_id' => $method->id,
            'channel_id' => $data['channel_id'] ?? $data['chan_id'] ?? 0,
            'amount' => $amount,
            'real_amount' => $amount,
            'fee' => $data['fee'] ?? 0.00,
            'subject' => $subject,
            'body' => $data['body'] ?? $subject,
            'status' => PaymentOrder::STATUS_PENDING,
            'client_ip' => $data['client_ip'] ?? '',
            'expire_at' => $data['expire_at'] ?? $data['expire_time'] ?? date('Y-m-d H:i:s', time() + 1800),
            'extra' => $data['extra'] ?? [],
        ]);
    }

    /**
     * 订单退款（供易支付等接口调用）
     *
     * @param array $data
     *   - order_id: 系统订单号（必填）
     *   - refund_amount: 退款金额（必填）
     *   - refund_reason: 退款原因（可选）
     * @return array
     */
    public function refundOrder(array $data): array
    {
        $orderId = (string)($data['order_id'] ?? $data['pay_order_id'] ?? '');
        $refundAmount = (float)($data['refund_amount'] ?? 0);

        if ($orderId === '') {
            throw new BadRequestException('订单号不能为空');
        }
        if ($refundAmount <= 0) {
            throw new BadRequestException('退款金额必须大于0');
        }

        // 1. 查询订单
        $order = $this->orderRepository->findByOrderId($orderId);
        if (!$order) {
            throw new NotFoundException('订单不存在');
        }

        // 2. 验证订单状态
        if ($order->status !== PaymentOrder::STATUS_SUCCESS) {
            throw new BadRequestException('订单状态不允许退款');
        }

        // 3. 验证退款金额
        if ($refundAmount > $order->amount) {
            throw new BadRequestException('退款金额不能大于订单金额');
        }

        // 4. 查询通道
        $channel = $this->channelRepository->find($order->channel_id);
        if (!$channel) {
            throw new NotFoundException('支付通道不存在');
        }

        // 5. 查询支付方式
        $method = $this->methodRepository->find($order->method_id);
        if (!$method) {
            throw new NotFoundException('支付方式不存在');
        }

        // 6. 实例化插件并初始化（通过插件服务）
        $plugin = $this->pluginService->getPluginInstance($channel->plugin_code);

        $channelConfig = array_merge(
            $channel->getConfigArray(),
            ['enabled_products' => $channel->getEnabledProducts()]
        );
        $plugin->init($method->method_code, $channelConfig);

        // 7. 调用插件退款
        $refundData = [
            'order_id' => $order->order_id,
            'chan_order_no' => $order->chan_order_no,
            'chan_trade_no' => $order->chan_trade_no,
            'refund_amount' => $refundAmount,
            'refund_reason' => $data['refund_reason'] ?? '',
        ];

        $refundResult = $plugin->refund($refundData, $channelConfig);

        // 8. 如果是全额退款则关闭订单
        if ($refundAmount >= $order->amount) {
            $this->orderRepository->updateById($order->id, [
                'status' => PaymentOrder::STATUS_CLOSED,
                'extra' => array_merge($order->extra ?? [], [
                    'refund_info' => $refundResult,
                ]),
            ]);
        }

        return [
            'order_id' => $order->order_id,
            'refund_amount' => $refundAmount,
            'refund_result' => $refundResult,
        ];
    }

    /**
     * 生成支付订单号
     */
    private function generateOrderId(): string
    {
        return 'P' . date('YmdHis') . mt_rand(100000, 999999);
    }
}
