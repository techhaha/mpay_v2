<?php

/**
 * 为统一回调日志补充退款单关联字段。
 */
return new class {
    public string $version = '202609150001';
    public string $name = 'add_refund_no_to_pay_callback_log';

    /**
     * 执行迁移。
     *
     * @param \PDO $pdo 数据库连接
     */
    public function up(\PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'ma_pay_callback_log', 'refund_no')) {
            $pdo->exec(
                "ALTER TABLE ma_pay_callback_log "
                . "ADD COLUMN refund_no varchar(32) NOT NULL DEFAULT '' COMMENT '退款单号，支付回调为空' AFTER pay_no"
            );
        }

        if (!$this->indexExists($pdo, 'ma_pay_callback_log', 'idx_refund_created')) {
            $pdo->exec(
                'ALTER TABLE ma_pay_callback_log '
                . 'ADD KEY idx_refund_created (refund_no, created_at)'
            );
        }
    }

    /**
     * 判断字段是否存在。
     */
    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(1) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * 判断索引是否存在。
     */
    private function indexExists(\PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(1) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    }
};
