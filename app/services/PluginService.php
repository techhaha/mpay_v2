<?php

namespace app\services;

use app\common\base\BaseService;
use app\common\contracts\PaymentInterface;
use app\common\contracts\PayPluginInterface;
use app\exceptions\NotFoundException;
use app\repositories\PaymentPluginRepository;

/**
 * 插件服务
 *
 * 负责与支付插件注册表和具体插件交互，供后台控制器等调用
 */
class PluginService extends BaseService
{
    public function __construct(
        protected PaymentPluginRepository $pluginRepository
    ) {
    }

    /**
     * 获取所有可用插件列表
     *
     * @return array<array{code:string,name:string,supported_methods:array}>
     */
    public function listPlugins(): array
    {
        $rows = $this->pluginRepository->getActivePlugins();

        $plugins = [];
        foreach ($rows as $row) {
            $pluginCode = $row->code;

            $pluginName = (string)($row->name ?? '');
            $supportedMethods = is_array($row->pay_types ?? null) ? (array)$row->pay_types : [];

            // 如果数据库里缺少元信息，则回退到实例化插件并写回数据库
            if ($pluginName === '' || $supportedMethods === []) {
                try {
                    $plugin = $this->resolvePlugin($pluginCode, (string)($row->class_name ?? ''));
                    $this->syncPluginMeta($pluginCode, $plugin);
                    $pluginName = $plugin->getName();
                    $supportedMethods = (array)$plugin->getEnabledPayTypes();
                } catch (\Throwable $e) {
                    // 忽略无法实例化的插件
                    continue;
                }
            }

            $plugins[] = [
                'code'              => $pluginCode,
                'name'              => $pluginName,
                'supported_methods' => $supportedMethods,
            ];
        }

        return $plugins;
    }

    /**
     * 获取插件配置 Schema
     */
    public function getConfigSchema(string $pluginCode, string $methodCode): array
    {
        $row = $this->pluginRepository->findActiveByCode($pluginCode);
        if ($row && is_array($row->config_schema ?? null) && $row->config_schema !== []) {
            return (array)$row->config_schema;
        }

        $plugin = $this->getPluginInstance($pluginCode);
        $schema = (array)$plugin->getConfigSchema();
        $this->syncPluginMeta($pluginCode, $plugin);
        return $schema;
    }

    /**
     * 获取插件支持的支付产品列表
     */
    public function getSupportedProducts(string $pluginCode, string $methodCode): array
    {
        /** @var mixed $plugin */
        $plugin = $this->getPluginInstance($pluginCode);
        if (method_exists($plugin, 'getSupportedProducts')) {
            return (array)$plugin->getSupportedProducts($methodCode);
        }
        return [];
    }

    /**
     * 从表单数据中提取插件配置参数（根据插件 Schema）
     */
    public function buildConfigFromForm(string $pluginCode, string $methodCode, array $formData): array
    {
        $configSchema = $this->getConfigSchema($pluginCode, $methodCode);

        $configJson = [];
        if (isset($configSchema['fields']) && is_array($configSchema['fields'])) {
            foreach ($configSchema['fields'] as $field) {
                $fieldName = $field['field'] ?? '';
                if ($fieldName && array_key_exists($fieldName, $formData)) {
                    $configJson[$fieldName] = $formData[$fieldName];
                }
            }
        }

        return $configJson;
    }

    /**
     * 对外统一提供：根据插件编码获取插件实例
     */
    public function getPluginInstance(string $pluginCode): PaymentInterface&PayPluginInterface
    {
        $row = $this->pluginRepository->findActiveByCode($pluginCode);
        if (!$row) {
            throw new NotFoundException('支付插件未注册或已禁用：' . $pluginCode);
        }

        return $this->resolvePlugin($pluginCode, $row->class_name);
    }

    /**
     * 根据插件编码和 class_name 解析并实例化插件
     */
    private function resolvePlugin(string $pluginCode, ?string $className = null): PaymentInterface&PayPluginInterface
    {
        $class = $className ?: (ucfirst($pluginCode) . 'Payment');
        // 允许 DB 中只存短类名（如 AlipayPayment），这里统一补全命名空间
        if ($class !== '' && !str_contains($class, '\\')) {
            $class = 'app\\common\\payment\\' . $class;
        }

        if (!class_exists($class)) {
            throw new NotFoundException('支付插件类不存在：' . $class);
        }

        $plugin = new $class();
        if (!$plugin instanceof PaymentInterface || !$plugin instanceof PayPluginInterface) {
            throw new NotFoundException('支付插件类型错误：' . $class);
        }

        return $plugin;
    }

    /**
     * 把插件元信息写回数据库，供“列表/Schema 直接从DB读取”
     */
    private function syncPluginMeta(string $pluginCode, PaymentInterface&PayPluginInterface $plugin): void
    {
        $payTypes = (array)$plugin->getEnabledPayTypes();
        $transferTypes = method_exists($plugin, 'getEnabledTransferTypes') ? (array)$plugin->getEnabledTransferTypes() : [];
        $configSchema = (array)$plugin->getConfigSchema();

        $author = method_exists($plugin, 'getAuthorName') ? (string)$plugin->getAuthorName() : '';
        $link = method_exists($plugin, 'getAuthorLink') ? (string)$plugin->getAuthorLink() : '';

        $this->pluginRepository->upsertByCode($pluginCode, [
            'name'            => $plugin->getName(),
            'pay_types'      => $payTypes,
            'transfer_types' => $transferTypes,
            'config_schema'  => $configSchema,
            'author'         => $author,
            'link'           => $link,
        ]);
    }
}

