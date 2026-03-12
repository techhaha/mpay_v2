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
            $pluginCode = $row->plugin_code;

            try {
                $plugin = $this->resolvePlugin($pluginCode, $row->class_name);
                $plugins[] = [
                    'code'             => $pluginCode,
                    'name'             => $plugin->getName(),
                    'supported_methods'=> $plugin->getEnabledPayTypes(),
                ];
            } catch (\Throwable $e) {
                // 忽略无法实例化的插件
                continue;
            }
        }

        return $plugins;
    }

    /**
     * 获取插件配置 Schema
     */
    public function getConfigSchema(string $pluginCode, string $methodCode): array
    {
        $plugin = $this->getPluginInstance($pluginCode);
        return $plugin->getConfigSchema();
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
        $plugin       = $this->getPluginInstance($pluginCode);
        $configSchema = $plugin->getConfigSchema();

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
        $class = $className ?: 'app\\common\\payment\\' . ucfirst($pluginCode) . 'Payment';

        if (!class_exists($class)) {
            throw new NotFoundException('支付插件类不存在：' . $class);
        }

        $plugin = new $class();
        if (!$plugin instanceof PaymentInterface || !$plugin instanceof PayPluginInterface) {
            throw new NotFoundException('支付插件类型错误：' . $class);
        }

        return $plugin;
    }
}

