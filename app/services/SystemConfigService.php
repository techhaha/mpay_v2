<?php

namespace app\services;

use app\common\base\BaseService;
use app\repositories\SystemConfigRepository;

/**
 * 系统配置服务
 */
class SystemConfigService extends BaseService
{
    public function __construct(
        protected SystemConfigRepository $configRepository
    ) {
    }

    /**
     * 根据配置键名获取配置值
     *
     * @param string $configKey
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getValue(string $configKey, $default = null)
    {
        $value = $this->configRepository->getValueByKey($configKey);
        return $value !== null ? $value : $default;
    }

    /**
     * 根据配置键名数组批量获取配置值
     *
     * @param array $configKeys
     * @return array 返回 ['config_key' => 'config_value'] 格式的数组
     */
    public function getValues(array $configKeys): array
    {
        return $this->configRepository->getValuesByKeys($configKeys);
    }

    /**
     * 保存配置值
     *
     * @param string $configKey
     * @param mixed $configValue
     * @return bool
     */
    public function setValue(string $configKey, $configValue): bool
    {
        // 如果是数组或对象，转换为JSON字符串
        if (is_array($configValue) || is_object($configValue)) {
            $configValue = json_encode($configValue, JSON_UNESCAPED_UNICODE);
        } else {
            $configValue = (string) $configValue;
        }

        return $this->configRepository->updateOrCreate($configKey, $configValue);
    }

    /**
     * 批量保存配置值
     *
     * @param array $configs 格式：['config_key' => 'config_value']
     * @return bool
     */
    public function setValues(array $configs): bool
    {
        // 处理数组和对象类型的值
        $processedConfigs = [];
        foreach ($configs as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $processedConfigs[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $processedConfigs[$key] = (string) $value;
            }
        }

        return $this->configRepository->batchUpdateOrCreate($processedConfigs);
    }
}

