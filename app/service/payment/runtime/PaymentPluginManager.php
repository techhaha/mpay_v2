<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;

/**
 * 支付插件门面服务。
 *
 * 对外保留原有调用契约，内部委托给插件工厂服务。
 */
class PaymentPluginManager extends BaseService
{
    public function __construct(
        protected PaymentPluginFactoryService $factoryService
    ) {
    }

    public function createByChannel(PaymentChannel|int $channel, ?int $payTypeId = null, bool $allowDisabled = false): PaymentInterface & PayPluginInterface
    {
        return $this->factoryService->createByChannel($channel, $payTypeId, $allowDisabled);
    }

    public function createByPayOrder(PayOrder $payOrder, bool $allowDisabled = true): PaymentInterface & PayPluginInterface
    {
        return $this->factoryService->createByPayOrder($payOrder, $allowDisabled);
    }

    public function ensureChannelSupportsPayType(PaymentChannel $channel, int $payTypeId): void
    {
        $this->factoryService->ensureChannelSupportsPayType($channel, $payTypeId);
    }

    public function pluginPayTypes(string $pluginCode, bool $allowDisabled = false): array
    {
        return $this->factoryService->pluginPayTypes($pluginCode, $allowDisabled);
    }
}
