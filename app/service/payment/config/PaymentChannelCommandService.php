<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\EventConstant;
use app\common\constant\RouteConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentChannel;
use app\repository\merchant\base\MerchantRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\repository\payment\config\PaymentTypeRepository;
use Webman\Event\Event;

/**
 * 支付通道命令服务。
 *
 * 负责支付通道的新增、修改、删除以及写入前的商户、插件和支付方式约束校验。
 *
 * @property MerchantRepository $merchantRepository 商户仓库
 * @property PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
 * @property PaymentPluginRepository $paymentPluginRepository 支付插件仓库
 * @property PaymentTypeRepository $paymentTypeRepository 支付类型仓库
 */
class PaymentChannelCommandService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param MerchantRepository $merchantRepository 商户仓库
     * @param PaymentChannelRepository $paymentChannelRepository 支付渠道仓库
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @param PaymentTypeRepository $paymentTypeRepository 支付类型仓库
     * @return void
     */
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    /**
     * 按 ID 查询支付通道。
     *
     * @param int $id 支付通道ID
     * @return PaymentChannel|null 支付通道模型
     */
    public function findById(int $id): ?PaymentChannel
    {
        return $this->paymentChannelRepository->find($id);
    }

    /**
     * 新增支付通道。
     *
     * @param array $data 写入数据
     * @return PaymentChannel 新增后的支付通道模型
     * @throws PaymentException
     */
    public function create(array $data): PaymentChannel
    {
        // 新增通道前先校验名称、商户归属和插件支付方式兼容性。
        $this->assertPlatformPayload($data);
        $this->assertChannelNameUnique((string) ($data['name'] ?? ''));
        $this->assertMerchantExists($data);
        $this->assertPluginSupportsPayType($data);

        $channel = $this->paymentChannelRepository->create($data);
        $this->dispatchWatcherConfigChanged('create', $channel);

        return $channel;
    }

    /**
     * 更新支付通道。
     *
     * @param int $id 支付通道ID
     * @param array $data 写入数据
     * @return PaymentChannel|null 更新后的支付通道模型
     * @throws PaymentException
     */
    public function update(int $id, array $data): ?PaymentChannel
    {
        $current = $this->paymentChannelRepository->find($id);
        if (!$current) {
            return null;
        }

        // 更新通道时同样要先拦住冲突配置，避免保存后才发现路由不可用。
        $this->assertPlatformChannel($current);
        $this->assertPlatformPayload($data, false);
        $this->assertChannelNameUnique((string) ($data['name'] ?? ''), $id);
        $this->assertMerchantExists($data);
        $this->assertPluginSupportsPayType($data);

        if (!$this->paymentChannelRepository->updateById($id, $data)) {
            return null;
        }

        $channel = $this->paymentChannelRepository->find($id);
        if ($channel) {
            $this->dispatchWatcherConfigChanged('update', $channel);
        }

        return $channel;
    }

    /**
     * 删除支付通道。
     *
     * @param int $id 支付通道ID
     * @return bool 是否删除成功
     */
    public function delete(int $id): bool
    {
        $channel = $this->paymentChannelRepository->find($id);
        if (!$channel) {
            return false;
        }
        $this->assertPlatformChannel($channel);

        $deleted = $this->paymentChannelRepository->deleteById($id);
        if ($deleted && $channel) {
            $this->dispatchWatcherConfigChanged('delete', $channel);
        }

        return $deleted;
    }

    /**
     * 校验后台写入的通道归属只能是平台通道。
     *
     * @param array $data 写入数据
     * @param bool $requireFields 是否要求携带完整归属字段
     * @return void
     * @throws PaymentException
     */
    private function assertPlatformPayload(array $data, bool $requireFields = true): void
    {
        if ($requireFields || array_key_exists('merchant_id', $data)) {
            if ((int) ($data['merchant_id'] ?? 0) !== 0) {
                throw new PaymentException('管理后台只能维护平台通道，商户自建通道请到商户后台处理', 40216);
            }
        }

        if ($requireFields || array_key_exists('channel_mode', $data)) {
            if ((int) ($data['channel_mode'] ?? RouteConstant::CHANNEL_MODE_COLLECT) !== RouteConstant::CHANNEL_MODE_COLLECT) {
                throw new PaymentException('管理后台路由通道必须是平台代收通道', 40217);
            }
        }
    }

    /**
     * 校验已有通道是否允许被管理后台写操作修改。
     *
     * @param PaymentChannel $channel 支付通道
     * @return void
     * @throws PaymentException
     */
    private function assertPlatformChannel(PaymentChannel $channel): void
    {
        if ((int) $channel->merchant_id !== 0 || (int) $channel->channel_mode !== RouteConstant::CHANNEL_MODE_COLLECT) {
            throw new PaymentException('商户自建通道只允许查看，不能在管理后台修改', 40218, [
                'channel_id' => (int) $channel->id,
                'merchant_id' => (int) $channel->merchant_id,
            ]);
        }
    }

    /**
     * 校验通道所属商户是否存在。
     *
     * @param array $data 写入数据
     * @return void
     * @throws PaymentException
     */
    private function assertMerchantExists(array $data): void
    {
        if (!array_key_exists('merchant_id', $data)) {
            return;
        }

        $merchantId = (int) $data['merchant_id'];
        // merchant_id 为空或为 0 时通常表示通道草稿，这里不强制拦截。
        if ($merchantId === 0) {
            return;
        }

        if (!$this->merchantRepository->find($merchantId)) {
            throw new PaymentException('所属商户不存在', 40209, [
                'merchant_id' => $merchantId,
            ]);
        }
    }

    /**
     * 校验支付插件是否支持当前支付方式。
     *
     * @param array $data 写入数据
     * @return void
     * @throws PaymentException
     */
    private function assertPluginSupportsPayType(array $data): void
    {
        $pluginCode = trim((string) ($data['plugin_code'] ?? ''));
        $payTypeId = (int) ($data['pay_type_id'] ?? 0);

        // 草稿态允许只填一半字段，只有插件和支付方式都明确时才做交叉校验。
        if ($pluginCode === '' || $payTypeId <= 0) {
            return;
        }

        $plugin = $this->paymentPluginRepository->findByCode($pluginCode);
        $paymentType = $this->paymentTypeRepository->find($payTypeId);

        if (!$plugin || !$paymentType) {
            return;
        }

        // 插件支持的支付方式可能来自 JSON 配置，先统一压成编码列表再比对。
        $payTypes = is_array($plugin->pay_types) ? $plugin->pay_types : [];
        $payTypeCodes = array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $payTypes)));
        $payTypeCode = trim((string) $paymentType->code);

        if ($payTypeCode === '' || !in_array($payTypeCode, $payTypeCodes, true)) {
            throw new PaymentException('支付插件不支持当前支付方式', 40210, [
                'plugin_code' => $pluginCode,
                'pay_type_code' => $payTypeCode,
            ]);
        }
    }

    /**
     * 校验通道名称唯一。
     *
     * @param string $name 通道名称
     * @param int $ignoreId 排除的通道ID
     * @return void
     * @throws PaymentException
     */
    private function assertChannelNameUnique(string $name, int $ignoreId = 0): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        if ($this->paymentChannelRepository->existsByName($name, $ignoreId)) {
            throw new PaymentException('通道名称已存在', 40215, [
                'name' => $name,
                'ignore_id' => $ignoreId,
            ]);
        }
    }

    /**
     * 发送网页流水监听配置刷新事件。
     *
     * @param string $action 操作
     * @param PaymentChannel $channel 支付通道
     * @return void
     */
    private function dispatchWatcherConfigChanged(string $action, PaymentChannel $channel): void
    {
        Event::dispatch(EventConstant::PAYMENT_RECEIPT_WATCHER_CONFIG_CHANGED, [
            'source' => 'payment_channel',
            'action' => $action,
            'channel_id' => (int) $channel->id,
            'plugin_code' => (string) $channel->plugin_code,
            'api_config_id' => (int) $channel->api_config_id,
        ]);
    }
}
