<?php

namespace app\service\payment\config;

use app\common\base\BaseService;
use app\common\interface\PayPluginInterface;
use app\common\interface\PaymentInterface;
use app\exception\PaymentException;
use app\model\payment\PaymentPlugin;
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
     * @return void
     */
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository
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
                'config_schema' => $plugin->getConfigSchema(),
                'pay_types' => $plugin->getEnabledPayTypes(),
                'transfer_types' => $plugin->getEnabledTransferTypes(),
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
                $payload = array_merge($row, [
                    'status' => (int) ($current->status ?? 1),
                    'allow_merchant' => (int) ($current->allow_merchant ?? 0),
                    'remark' => (string) ($current->remark ?? ''),
                ]);

                if ($current) {
                    // 已存在的插件只覆盖元信息，不改动人工维护的状态和备注。
                    $current->fill($payload);
                    $current->save();
                    unset($existing[$code]);
                    continue;
                }

                $this->paymentPluginRepository->create($payload);
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

