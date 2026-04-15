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
 * 负责扫描插件目录、实例化插件类并同步数据库定义。
 */
class PaymentPluginSyncService extends BaseService
{
    public function __construct(
        protected PaymentPluginRepository $paymentPluginRepository
    ) {}

    /**
     * 从插件目录刷新并同步支付插件定义。
     */
    public function refreshFromClasses(): array
    {
        $directory = base_path() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'payment';
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];

        $rows = [];
        foreach ($files as $file) {
            $shortClassName = pathinfo($file, PATHINFO_FILENAME);
            $className = 'app\\common\\payment\\' . $shortClassName;
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
                    'remark' => (string) ($current->remark ?? ''),
                ]);

                if ($current) {
                    $current->fill($payload);
                    $current->save();
                    unset($existing[$code]);
                    continue;
                }

                $this->paymentPluginRepository->create($payload);
            }

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
