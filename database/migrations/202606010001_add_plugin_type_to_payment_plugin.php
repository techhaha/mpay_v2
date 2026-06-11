<?php

/**
 * 为支付插件注册表补充插件类型字段。
 */
return new class {
    public string $version = '202606010001';
    public string $name = 'add_plugin_type_to_payment_plugin';

    /**
     * 执行迁移。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'ma_payment_plugin', 'plugin_type')) {
            $pdo->exec(
                "ALTER TABLE `ma_payment_plugin` " .
                "ADD COLUMN `plugin_type` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '插件类型：1-直连支付插件,2-挂机监听插件,3-后台监听插件' AFTER `class_name`"
            );
        }

        if (!$this->indexExists($pdo, 'ma_payment_plugin', 'idx_plugin_type_status')) {
            $pdo->exec("ALTER TABLE `ma_payment_plugin` ADD KEY `idx_plugin_type_status` (`plugin_type`, `status`)");
        }

        $pdo->exec('UPDATE `ma_payment_plugin` SET `plugin_type` = 1 WHERE `plugin_type` NOT IN (1, 2, 3)');
    }

    /**
     * 判断字段是否存在。
     *
     * @param \PDO $pdo 数据库连接
     * @param string $table 表名
     * @param string $column 字段名
     * @return bool 是否存在
     */
    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * 判断索引是否存在。
     *
     * @param \PDO $pdo 数据库连接
     * @param string $table 表名
     * @param string $index 索引名
     * @return bool 是否存在
     */
    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    }
};
