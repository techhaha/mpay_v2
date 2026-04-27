<?php

namespace app\service\payment\runtime;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\interface\PaymentInterface;
use app\common\interface\PayPluginInterface;
use app\exception\PaymentException;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentPlugin;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginConfRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\repository\payment\config\PaymentTypeRepository;

/**
 * 支付插件工厂服务。
 *
 * 负责解析插件定义、装配配置并实例化插件。
 *
 * @property PaymentPluginRepository $paymentPluginRepository 支付插件仓库
 * @property PaymentPluginConfRepository $paymentPluginConfRepository 支付插件配置仓库
 * @property PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 */
class PaymentPluginFactoryService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @param PaymentPluginConfRepository $paymentPluginConfRepository 支付插件配置仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @return void
     */
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentPluginConfRepository $paymentPluginConfRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentTypeRepository $paymentTypeRepository
    ) {}

    /**
     * 根据渠道创建支付插件实例。
     *
     * @param PaymentChannel|int $channel 渠道对象或渠道ID
     * @param int|null $payTypeId 支付类型ID
     * @param bool $allowDisabled 是否允许已禁用插件
     * @return PaymentInterface&PayPluginInterface 插件实例
     * @throws PaymentException
     */
    public function createByChannel(PaymentChannel|int $channel, ?int $payTypeId = null, bool $allowDisabled = false): PaymentInterface & PayPluginInterface
    {
        $channelModel = $channel instanceof PaymentChannel
            ? $channel
            : $this->paymentChannelRepository->find((int) $channel);

        if (!$channelModel) {
            throw new PaymentException('支付通道不存在', 40402, ['channel_id' => (int) $channel]);
        }

        $plugin = $this->resolvePlugin((string) $channelModel->plugin_code, $allowDisabled);
        // 如果外部没有额外指定支付方式，就沿用通道自身绑定的支付方式，确保插件校验口径一致。
        $payTypeCode = $this->resolvePayTypeCode((int) ($payTypeId ?: $channelModel->pay_type_id));
        if (!$allowDisabled && !$this->pluginSupportsPayType($plugin, $payTypeCode)) {
            throw new PaymentException('支付插件不支持当前支付方式', 40210, [
                'plugin_code' => (string) $plugin->code,
                'pay_type_code' => $payTypeCode,
                'channel_id' => (int) $channelModel->id,
            ]);
        }

        $instance = $this->instantiatePlugin((string) $plugin->class_name);
        $instance->init($this->buildChannelConfig($channelModel, $plugin));

        return $instance;
    }

    /**
     * 根据支付订单创建支付插件实例。
     *
     * @param PayOrder $payOrder 支付订单
     * @param bool $allowDisabled 是否允许已禁用插件
     * @return PaymentInterface&PayPluginInterface 插件实例
     */
    public function createByPayOrder(PayOrder $payOrder, bool $allowDisabled = true): PaymentInterface
    {
        // 支付单已经带了渠道和支付方式快照，这里直接复用渠道工厂逻辑，避免两套实例化口径分叉。
        return $this->createByChannel((int) $payOrder->channel_id, (int) $payOrder->pay_type_id, $allowDisabled);
    }

    /**
     * 校验渠道是否支持指定支付方式。
     *
     * @param PaymentChannel $channel 渠道
     * @param int $payTypeId 支付类型ID
     * @return void
     * @throws PaymentException
     */
    public function ensureChannelSupportsPayType(PaymentChannel $channel, int $payTypeId): void
    {
        // 只做能力校验，不实例化插件，便于后台在保存配置前先拦住不兼容组合。
        $plugin = $this->resolvePlugin((string) $channel->plugin_code, false);
        $payTypeCode = $this->resolvePayTypeCode($payTypeId);

        if (!$this->pluginSupportsPayType($plugin, $payTypeCode)) {
            throw new PaymentException('支付插件不支持当前支付方式', 40210, [
                'plugin_code' => (string) $plugin->code,
                'pay_type_code' => $payTypeCode,
                'channel_id' => (int) $channel->id,
            ]);
        }
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
        $plugin = $this->resolvePlugin($pluginCode, $allowDisabled);

        return $this->normalizeCodes($plugin->pay_types ?? []);
    }

    /**
     * 组装渠道初始化配置。
     *
     * @param PaymentChannel $channel 渠道
     * @param PaymentPlugin $plugin 插件
     * @return array 初始化配置
     * @throws PaymentException
     */
    private function buildChannelConfig(PaymentChannel $channel, PaymentPlugin $plugin): array
    {
        $config = [];
        $configId = (int) $channel->api_config_id;

        if ($configId > 0) {
            // 渠道绑定了配置时，先把配置表里的内容作为插件初始化基础数据。
            $pluginConf = $this->paymentPluginConfRepository->find($configId);
            if (!$pluginConf) {
                throw new PaymentException('支付插件配置不存在', 40403, [
                    'api_config_id' => $configId,
                    'channel_id' => (int) $channel->id,
                ]);
            }

            if ((string) $pluginConf->plugin_code !== (string) $plugin->code) {
                throw new PaymentException('支付插件与配置不匹配', 40211, [
                    'channel_id' => (int) $channel->id,
                    'plugin_code' => (string) $plugin->code,
                    'config_plugin_code' => (string) $pluginConf->plugin_code,
                ]);
            }

            $config = (array) ($pluginConf->config ?? []);
            // 结算周期信息属于配置层，插件可以直接读取，不必再去查数据库。
            $config['settlement_cycle_type'] = (int) ($pluginConf->settlement_cycle_type ?? 1);
            $config['settlement_cutoff_time'] = (string) ($pluginConf->settlement_cutoff_time ?? '23:59:59');
        }

        // 以下字段是所有插件都通用的运行时上下文。
        $config['plugin_code'] = (string) $plugin->code;
        $config['plugin_name'] = (string) $plugin->name;
        $config['channel_id'] = (int) $channel->id;
        $config['merchant_id'] = (int) $channel->merchant_id;
        $config['channel_mode'] = (int) $channel->channel_mode;
        $config['pay_type_id'] = (int) $channel->pay_type_id;
        $config['api_config_id'] = $configId;
        $config['enabled_pay_types'] = $this->normalizeCodes($plugin->pay_types ?? []);
        $config['enabled_transfer_types'] = $this->normalizeCodes($plugin->transfer_types ?? []);

        return $config;
    }

    /**
     * 实例化支付插件。
     *
     * @param string $className 类名
     * @return PaymentInterface&PayPluginInterface 插件实例
     * @throws PaymentException
     */
    private function instantiatePlugin(string $className): PaymentInterface & PayPluginInterface
    {
        $className = $this->resolvePluginClassName($className);
        if ($className === '') {
            throw new PaymentException('支付插件未配置实现类', 40212);
        }

        if (!class_exists($className)) {
            throw new PaymentException('支付插件实现类不存在', 40404, ['class_name' => $className]);
        }

        // 通过容器实例化插件，便于插件内部继续使用依赖注入。
        $instance = container_make($className, []);
        // 插件必须同时实现动作接口和元信息接口，否则工厂无法正常调用和展示。
        if (!$instance instanceof PaymentInterface || !$instance instanceof PayPluginInterface) {
            throw new PaymentException('支付插件必须同时实现 PaymentInterface 与 PayPluginInterface', 40213, ['class_name' => $className]);
        }

        return $instance;
    }

    /**
     * 规范化插件类名。
     *
     * @param string $className 类名
     * @return string 完整类名
     */
    private function resolvePluginClassName(string $className): string
    {
        $className = trim($className);
        if ($className === '') {
            return '';
        }

        if (str_contains($className, '\\')) {
            return $className;
        }

        return 'app\\common\\payment\\' . $className;
    }

    /**
     * 根据编码解析支付插件。
     *
     * @param string $pluginCode 插件编码
     * @param bool $allowDisabled 是否允许已禁用插件
     * @return PaymentPlugin 插件模型
     * @throws PaymentException
     */
    private function resolvePlugin(string $pluginCode, bool $allowDisabled): PaymentPlugin
    {
        /** @var PaymentPlugin|null $plugin */
        $plugin = $this->paymentPluginRepository->findByCode($pluginCode);
        if (!$plugin) {
            throw new PaymentException('支付插件不存在', 40401, ['plugin_code' => $pluginCode]);
        }

        if (!$allowDisabled && (int) $plugin->status !== CommonConstant::STATUS_ENABLED) {
            throw new PaymentException('支付插件已禁用', 40214, ['plugin_code' => $pluginCode]);
        }

        return $plugin;
    }

    /**
     * 根据支付类型 ID 解析支付方式编码。
     *
     * @param int $payTypeId 支付类型ID
     * @return string 支付方式编码
     * @throws PaymentException
     */
    private function resolvePayTypeCode(int $payTypeId): string
    {
        $paymentType = $this->paymentTypeRepository->find($payTypeId);
        if (!$paymentType) {
            throw new PaymentException('支付方式不存在', 40405, ['pay_type_id' => $payTypeId]);
        }

        return trim((string) $paymentType->code);
    }

    /**
     * 判断插件是否支持指定支付方式。
     *
     * @param PaymentPlugin $plugin 插件
     * @param string $payTypeCode 支付方式编码
     * @return bool 是否支持
     */
    private function pluginSupportsPayType(PaymentPlugin $plugin, string $payTypeCode): bool
    {
        $payTypeCode = trim($payTypeCode);
        if ($payTypeCode === '') {
            return false;
        }

        return in_array($payTypeCode, $this->normalizeCodes($plugin->pay_types ?? []), true);
    }

    /**
     * 规范化编码列表。
     *
     * 支持数组和 JSON 字符串两种输入形式，输出去重后的纯字符串数组。
     *
     * @param array|string|null $codes 原始编码集合
     * @return array<int, string> 编码列表
     */
    private function normalizeCodes(mixed $codes): array
    {
        if (is_string($codes)) {
            $decoded = json_decode($codes, true);
            $codes = is_array($decoded) ? $decoded : [$codes];
        }

        if (!is_array($codes)) {
            return [];
        }

        $normalized = [];
        foreach ($codes as $code) {
            $value = trim((string) $code);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
