<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\interface\PayPluginInterface;
use app\common\interface\PaymentInterface;
use app\exception\PaymentException;
use app\model\payment\PaymentPlugin;
use app\repository\payment\config\PaymentPluginOnboardingConfRepository;
use app\repository\payment\config\PaymentPluginRepository;

/**
 * 支付插件同步服务。
 *
 * 负责扫描插件目录、实例化插件类并同步数据库中的插件定义。
 *
 * @property PaymentPluginRepository $paymentPluginRepository 支付插件仓库
 */
class PaymentPluginSyncService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param PaymentPluginRepository $paymentPluginRepository 支付插件仓库
     * @param PaymentPluginOnboardingConfRepository $onboardingConfRepository 插件进件配置仓库
     * @return void
     */
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository,
        protected PaymentPluginOnboardingConfRepository $onboardingConfRepository
    ) {}

    /**
     * 从插件目录刷新并同步支付插件定义。
     *
     * @return array{count: int, plugins: array<int, PaymentPlugin>} 同步结果
     * @throws PaymentException
     */
    public function refreshFromClasses(): array
    {
        $directory = base_path() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'payment';
        // 扫描固定目录下的插件类文件，每个文件都可能对应一个可同步的插件定义。
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];

        // 以插件 code 为键去重，避免同一个插件被多个类重复注册。
        $rows = [];
        foreach ($files as $file) {
            $shortClassName = pathinfo($file, PATHINFO_FILENAME);
            $className = 'app\\common\\payment\\' . $shortClassName;
            // 先实例化插件，再从实例上读取元信息作为同步源。
            $plugin = $this->instantiatePlugin($className);
            if (!$plugin) {
                continue;
            }

            $code = trim((string) $plugin->getCode());
            if ($code === '') {
                throw new PaymentException('支付插件编码不能为空', 40220, ['class_name' => $className]);
            }

            if (isset($rows[$code])) {
                throw new PaymentException('支付插件编码重复', 40221, [
                    'plugin_code' => $code,
                    'class_name' => $className,
                ]);
            }

            $rows[$code] = [
                'code' => $plugin->getCode(),
                'name' => $plugin->getName(),
                'class_name' => $shortClassName,
                'plugin_type' => $plugin->getPluginType(),
                'config_schema' => $plugin->getConfigSchema(),
                'pay_types' => $plugin->getEnabledPayTypes(),
                'transfer_types' => $plugin->getEnabledTransferTypes(),
                // 进件能力独立同步，后台进件模块只读取这两个字段，不复用支付通道能力。
                'onboarding_types' => method_exists($plugin, 'getOnboardingTypes') ? $plugin->getOnboardingTypes() : [],
                'onboarding_info' => method_exists($plugin, 'getOnboardingInfo') ? $plugin->getOnboardingInfo() : [],
                'version' => $plugin->getVersion(),
                'author' => $plugin->getAuthorName(),
                'link' => $plugin->getAuthorLink(),
            ];
        }

        // 先固定排序，再和数据库现有记录逐条对比，保证同步过程稳定可复现。
        ksort($rows);

        $existing = $this->paymentPluginRepository->query()
            ->get()
            ->keyBy('code')
            ->all();

        $this->transaction(function () use ($rows, $existing) {
            foreach ($rows as $code => $row) {
                /** @var PaymentPlugin|null $current */
                $current = $existing[$code] ?? null;
                if ($current) {
                    // 已存在的插件只覆盖元信息，不改动人工维护的状态和备注。
                    $payload = array_merge($row, [
                        'status' => (int) $current->status,
                        'allow_merchant' => (int) $current->allow_merchant,
                        'remark' => (string) $current->remark,
                    ]);
                    $current->fill($payload);
                    $current->save();
                    // 开发期以插件最新 schema 为准，清理进件配置里已经废弃的字段。
                    $this->pruneOnboardingConfigValues($code, (array) ($row['onboarding_info'] ?? []));
                    unset($existing[$code]);
                    continue;
                }

                // 新插件只写入插件元信息，启停、商户可见和备注走数据表默认值。
                $this->paymentPluginRepository->create($row);
                $this->pruneOnboardingConfigValues($code, (array) ($row['onboarding_info'] ?? []));
            }

            // 数据库里还残留、但文件中已不存在的插件，直接删除避免配置漂移。
            foreach ($existing as $plugin) {
                $plugin->delete();
            }
        });

        return [
            'count' => count($rows),
            'plugins' => $this->paymentPluginRepository->query()
                ->orderBy('code')
                ->get()
                ->values()
                ->all(),
        ];
    }

    /**
     * 按插件最新进件配置 schema 裁剪已保存的进件配置值。
     *
     * 插件刷新只保留当前 schema 声明的字段，避免接口路径等废弃字段继续随配置回填。
     *
     * @param string $pluginCode 插件编码
     * @param array<string, mixed> $onboardingInfo 插件进件能力声明
     * @return void
     */
    private function pruneOnboardingConfigValues(string $pluginCode, array $onboardingInfo): void
    {
        $allowedFields = $this->schemaFields((array) ($onboardingInfo['config_schema'] ?? []));
        $allowedMap = array_flip($allowedFields);

        $this->onboardingConfRepository->query()
            ->where('plugin_code', $pluginCode)
            ->get()
            ->each(function ($config) use ($allowedMap): void {
                $rawConfig = is_array($config->config) ? $config->config : [];
                // 这里只裁剪字段，不补默认值；必填缺失仍由创建/编辑保存时的校验负责提示。
                $prunedConfig = array_intersect_key($rawConfig, $allowedMap);
                if ($prunedConfig === $rawConfig) {
                    return;
                }

                $config->config = $prunedConfig;
                $config->save();
            });
    }

    /**
     * 提取 form-create schema 中声明的字段名。
     *
     * @param array<int, array<string, mixed>> $rules 表单规则
     * @return array<int, string> 字段名列表
     */
    private function schemaFields(array $rules): array
    {
        $fields = [];
        foreach ($this->flattenSchemaRules($rules) as $rule) {
            $field = trim((string) ($rule['field'] ?? ''));
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * 展开 form-create schema，便于读取分组或嵌套组件下的字段。
     *
     * @param array<int, array<string, mixed>> $rules 表单规则
     * @return array<int, array<string, mixed>>
     */
    private function flattenSchemaRules(array $rules): array
    {
        $flat = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $flat[] = $rule;
            if (is_array($rule['children'] ?? null)) {
                $flat = array_merge($flat, $this->flattenSchemaRules((array) $rule['children']));
            }
        }

        return $flat;
    }

    /**
     * 实例化插件类并过滤非支付插件类。
     *
     * @param string $className 插件类名
     * @return null|(PaymentInterface&PayPluginInterface) 支付插件实例
     */
    private function instantiatePlugin(string $className): null|(PaymentInterface & PayPluginInterface)
    {
        if (!class_exists($className)) {
            return null;
        }

        $instance = container_make($className, []);
        if (!$instance instanceof PayPluginInterface || !$instance instanceof PaymentInterface) {
            return null;
        }

        return $instance;
    }
}
