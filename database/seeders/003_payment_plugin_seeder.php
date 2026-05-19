<?php

use app\common\interface\PayPluginInterface;
use app\common\interface\PaymentInterface;

/**
 * 支付插件基础数据 Seeder。
 */
return new class {
    public string $name = 'payment_plugin_seeder';

    /**
     * 扫描支付插件并写入插件表。
     *
     * @param \PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<string, int> 执行摘要
     */
    public function run(\PDO $pdo, array $context = []): array
    {
        $directory = base_path(false) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'payment';
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);

        $statement = $pdo->prepare(
            'INSERT INTO `ma_payment_plugin` (`code`, `name`, `class_name`, `config_schema`, `pay_types`, `transfer_types`, `version`, `author`, `link`, `status`, `allow_merchant`, `remark`, `created_at`, `updated_at`) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, "", NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `class_name` = VALUES(`class_name`), `config_schema` = VALUES(`config_schema`), ' .
            '`pay_types` = VALUES(`pay_types`), `transfer_types` = VALUES(`transfer_types`), `version` = VALUES(`version`), `author` = VALUES(`author`), `link` = VALUES(`link`), `updated_at` = NOW()'
        );
        $count = 0;

        foreach ($files as $file) {
            $shortClassName = pathinfo($file, PATHINFO_FILENAME);
            $className = 'app\\common\\payment\\' . $shortClassName;
            if (!class_exists($className)) {
                continue;
            }

            $plugin = container_make($className, []);
            if (!$plugin instanceof PayPluginInterface || !$plugin instanceof PaymentInterface) {
                continue;
            }

            $code = trim((string) $plugin->getCode());
            if ($code === '') {
                continue;
            }

            $statement->execute([
                $code,
                (string) $plugin->getName(),
                $shortClassName,
                json_encode($plugin->getConfigSchema(), JSON_UNESCAPED_UNICODE),
                json_encode($plugin->getEnabledPayTypes(), JSON_UNESCAPED_UNICODE),
                json_encode($plugin->getEnabledTransferTypes(), JSON_UNESCAPED_UNICODE),
                (string) $plugin->getVersion(),
                (string) $plugin->getAuthorName(),
                (string) $plugin->getAuthorLink(),
            ]);
            $count++;
        }

        return ['count' => $count];
    }
};
