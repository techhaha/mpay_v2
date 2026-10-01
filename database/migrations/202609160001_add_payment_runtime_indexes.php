<?php

/**
 * 为支付主动查单和超时扫描补充联合索引。
 */
return new class {
    public string $version = '202609160001';
    public string $name = 'add_payment_runtime_indexes';

    public function up(\PDO $pdo): void
    {
        $this->addIndex($pdo, 'ma_pay_order', 'idx_status_request_id', '`status`, `request_at`, `id`');
        $this->addIndex($pdo, 'ma_pay_order', 'idx_status_expire_id', '`status`, `expire_at`, `id`');
    }

    public function down(\PDO $pdo): void
    {
        $this->dropIndex($pdo, 'ma_pay_order', 'idx_status_request_id');
        $this->dropIndex($pdo, 'ma_pay_order', 'idx_status_expire_id');
    }

    private function addIndex(\PDO $pdo, string $table, string $index, string $columns): void
    {
        if (!$this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        }
    }

    private function dropIndex(\PDO $pdo, string $table, string $index): void
    {
        if ($this->indexExists($pdo, $table, $index)) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }

    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    }
};
