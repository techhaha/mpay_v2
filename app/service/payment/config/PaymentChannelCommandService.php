<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\exception\PaymentException;
use app\model\payment\PaymentChannel;
use app\repository\merchant\base\MerchantRepository;
use app\repository\payment\config\PaymentChannelRepository;
use app\repository\payment\config\PaymentPluginRepository;
use app\repository\payment\config\PaymentTypeRepository;

/**
 * 支付通道命令服务。
 */
class PaymentChannelCommandService extends BaseService
{
    public function __construct(
        protected MerchantRepository $merchantRepository,
        protected PaymentChannelRepository $paymentChannelRepository,
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentTypeRepository $paymentTypeRepository
    ) {
    }

    public function findById(int $id): ?PaymentChannel
    {
        return $this->paymentChannelRepository->find($id);
    }

    public function create(array $data): PaymentChannel
    {
        $this->assertChannelNameUnique((string) ($data['name'] ?? ''));
        $this->assertMerchantExists($data);
        $this->assertPluginSupportsPayType($data);

        return $this->paymentChannelRepository->create($data);
    }

    public function update(int $id, array $data): ?PaymentChannel
    {
        $this->assertChannelNameUnique((string) ($data['name'] ?? ''), $id);
        $this->assertMerchantExists($data);
        $this->assertPluginSupportsPayType($data);

        if (!$this->paymentChannelRepository->updateById($id, $data)) {
            return null;
        }

        return $this->paymentChannelRepository->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->paymentChannelRepository->deleteById($id);
    }

    private function assertMerchantExists(array $data): void
    {
        if (!array_key_exists('merchant_id', $data)) {
            return;
        }

        $merchantId = (int) $data['merchant_id'];
        if ($merchantId === 0) {
            return;
        }

        if (!$this->merchantRepository->find($merchantId)) {
            throw new PaymentException('所属商户不存在', 40209, [
                'merchant_id' => $merchantId,
            ]);
        }
    }

    private function assertPluginSupportsPayType(array $data): void
    {
        $pluginCode = trim((string) ($data['plugin_code'] ?? ''));
        $payTypeId = (int) ($data['pay_type_id'] ?? 0);

        if ($pluginCode === '' || $payTypeId <= 0) {
            return;
        }

        $plugin = $this->paymentPluginRepository->findByCode($pluginCode);
        $paymentType = $this->paymentTypeRepository->find($payTypeId);

        if (!$plugin || !$paymentType) {
            return;
        }

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
}
