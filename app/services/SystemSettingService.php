<?php

namespace app\services;

use app\common\base\BaseService;
use app\exceptions\{InternalServerException, NotFoundException};
use support\Cache;

/**
 * 系统设置相关业务服务
 *
 * 负责：
 * - 字典配置（dict.json + 缓存）
 * - 系统设置 Tab 配置（tabs.json + 缓存）
 * - 表单配置（{tabKey}.json + 数据库配置值 + 缓存）
 */
class SystemSettingService extends BaseService
{
    /**
     * 缓存键：系统设置Tab
     */
    private const CACHE_KEY_TABS = 'system_base_config_tabs';

    /**
     * 缓存键前缀：系统设置表单配置
     */
    private const CACHE_KEY_FORM_PREFIX = 'system_base_config_form_';

    /**
     * 缓存键：所有字典
     */
    private const CACHE_KEY_DICT = 'system_dict_all';

    public function __construct(
        protected SystemConfigService $configService
    ) {
    }

    /**
     * 获取字典数据
     *
     * @param string $code 字典编码，不传返回全部
     * @return array
     */
    public function getDict(string $code = ''): array
    {
        $allDicts = Cache::get(self::CACHE_KEY_DICT);

        if (!is_array($allDicts)) {
            $jsonPath = config_path('system-file/dict.json');

            if (!file_exists($jsonPath)) {
                throw new InternalServerException('字典配置文件不存在');
            }

            $jsonContent = file_get_contents($jsonPath);
            $data = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                throw new InternalServerException('字典配置文件格式错误：' . json_last_error_msg());
            }

            $allDicts = $data;
            Cache::set(self::CACHE_KEY_DICT, $allDicts);
        }

        if ($code === '') {
            return $allDicts;
        }

        $dictsByCode = array_column($allDicts, null, 'code');
        $dict = $dictsByCode[$code] ?? null;

        if ($dict === null) {
            throw new NotFoundException('未找到指定的字典：' . $code);
        }

        return $dict;
    }

    /**
     * 获取所有系统设置 Tab 配置
     *
     * @return array
     */
    public function getTabs(): array
    {
        $cached = Cache::get(self::CACHE_KEY_TABS);
        if (is_array($cached)) {
            return $cached;
        }

        $configPath = config_path('base-config/tabs.json');
        if (!file_exists($configPath)) {
            throw new NotFoundException('Tab配置文件不存在');
        }

        $jsonContent = file_get_contents($configPath);
        $tabs = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InternalServerException('Tab配置文件格式错误：' . json_last_error_msg());
        }

        usort($tabs, function ($a, $b) {
            $sortA = $a['sort'] ?? 0;
            $sortB = $b['sort'] ?? 0;
            return $sortA <=> $sortB;
        });

        Cache::set(self::CACHE_KEY_TABS, $tabs);

        return $tabs;
    }

    /**
     * 获取指定 Tab 的表单配置（合并数据库值）
     *
     * @param string $tabKey
     * @return array
     */
    public function getFormConfig(string $tabKey): array
    {
        $cacheKey = self::CACHE_KEY_FORM_PREFIX . $tabKey;

        $formConfig = Cache::get($cacheKey);
        if (!is_array($formConfig)) {
            $configPath = config_path("base-config/{$tabKey}.json");

            if (!file_exists($configPath)) {
                throw new NotFoundException("表单配置文件不存在：{$tabKey}");
            }

            $jsonContent = file_get_contents($configPath);
            $formConfig = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InternalServerException('表单配置文件格式错误：' . json_last_error_msg());
            }

            Cache::set($cacheKey, $formConfig);
        }

        // 合并数据库配置值
        if (isset($formConfig['rules']) && is_array($formConfig['rules'])) {
            $fieldNames = [];
            foreach ($formConfig['rules'] as $rule) {
                if (isset($rule['field'])) {
                    $fieldNames[] = $rule['field'];
                }
            }

            if (!empty($fieldNames)) {
                $dbValues = $this->configService->getValues($fieldNames);

                foreach ($formConfig['rules'] as &$rule) {
                    if (isset($rule['field']) && isset($dbValues[$rule['field']])) {
                        $value = $dbValues[$rule['field']];

                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $rule['value'] = $decoded;
                        } else {
                            if (isset($rule['type'])) {
                                switch ($rule['type']) {
                                    case 'inputNumber':
                                        $rule['value'] = is_numeric($value) ? (float) $value : ($rule['value'] ?? 0);
                                        break;
                                    case 'switch':
                                        $rule['value'] = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
                                        break;
                                    default:
                                        $rule['value'] = $value;
                                }
                            } else {
                                $rule['value'] = $value;
                            }
                        }
                    }
                }
                unset($rule);
            }
        }

        Cache::set($cacheKey, $formConfig);

        return $formConfig;
    }

    /**
     * 保存表单配置
     *
     * @param string $tabKey
     * @param array $formData
     * @return void
     */
    public function saveFormConfig(string $tabKey, array $formData): void
    {
        $result = $this->configService->setValues($formData);

        if (!$result) {
            throw new InternalServerException('保存失败');
        }

        // 清理对应表单缓存
        $cacheKey = self::CACHE_KEY_FORM_PREFIX . $tabKey;
        Cache::delete($cacheKey);
    }
}


