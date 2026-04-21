<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;

/**
 * 支付插件服务。
 *
 * @property PaymentPluginFactoryService $factoryService 插件工厂服务
 */
class PaymentPluginManager extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginFactoryService $factoryService 插件工厂服务
     * @return void
     */
    public function __construct(
        protected PaymentPluginFactoryService $factoryService
    ) {
    }

    /**
     * 根据渠道创建支付插件实例。
     *
     * @param PaymentChannel|int $channel 渠道对象或渠道ID
     * @param int|null $payTypeId 支付类型ID
     * @param bool $allowDisabled 是否允许已禁用插件
     * @return PaymentInterface&PayPluginInterface 插件实例
     */
    public function createByChannel(PaymentChannel|int $channel, ?int $payTypeId = null, bool $allowDisabled = false): PaymentInterface & PayPluginInterface
    {
        return $this->factoryService->createByChannel($channel, $payTypeId, $allowDisabled);
    }

    /**
     * 根据支付订单创建支付插件实例。
     *
     * @param PayOrder $payOrder 支付订单
     * @param bool $allowDisabled 是否允许已禁用插件
     * @return PaymentInterface&PayPluginInterface 插件实例
     */
    public function createByPayOrder(PayOrder $payOrder, bool $allowDisabled = true): PaymentInterface & PayPluginInterface
    {
        return $this->factoryService->createByPayOrder($payOrder, $allowDisabled);
    }

    /**
     * 校验渠道是否支持指定支付方式。
     *
     * @param PaymentChannel $channel 渠道
     * @param int $payTypeId 支付类型ID
     * @return void
     */
    public function ensureChannelSupportsPayType(PaymentChannel $channel, int $payTypeId): void
    {
        $this->factoryService->ensureChannelSupportsPayType($channel, $payTypeId);
    }

    /**
     * 获取插件支持的支付方式编码。
     *
     * @param string $pluginCode 插件编码
     * @param bool $allowDisabled 是否允许已禁用插件
     * @return array 支付方式编码列表
     */
    public function pluginPayTypes(string $pluginCode, bool $allowDisabled = false): array
    {
        return $this->factoryService->pluginPayTypes($pluginCode, $allowDisabled);
    }
}



