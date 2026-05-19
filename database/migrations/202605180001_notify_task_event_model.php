<?php

/**
 * 商户通知任务事件模型迁移。
 */
return new class {
    public string $version = '202605180001';
    public string $name = 'notify_task_event_model';

    /**
     * 执行通知任务字段和索引调整。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'ma_notify_task', 'event_type')) {
            $pdo->exec("ALTER TABLE `ma_notify_task` ADD COLUMN `event_type` varchar(32) NOT NULL DEFAULT 'PAY_SUCCESS' COMMENT '通知事件类型：PAY_SUCCESS,REFUND_SUCCESS,SETTLEMENT_SUCCESS' AFTER `merchant_group_id`");
        }

        if (!$this->columnExists($pdo, 'ma_notify_task', 'ref_no')) {
            $pdo->exec("ALTER TABLE `ma_notify_task` ADD COLUMN `ref_no` varchar(64) NOT NULL DEFAULT '' COMMENT '事件引用单号' AFTER `event_type`");
        }

        $pdo->exec("UPDATE `ma_notify_task` SET `event_type` = 'PAY_SUCCESS' WHERE `event_type` = ''");
        $pdo->exec("UPDATE `ma_notify_task` SET `ref_no` = `pay_no` WHERE `ref_no` = '' AND `pay_no` <> ''");
        $pdo->exec("UPDATE `ma_notify_task` SET `ref_no` = `notify_no` WHERE `ref_no` = ''");

        if ($this->indexExists($pdo, 'ma_notify_task', 'uk_pay_no')) {
            $pdo->exec('ALTER TABLE `ma_notify_task` DROP INDEX `uk_pay_no`');
        }

        if (!$this->indexExists($pdo, 'ma_notify_task', 'uk_notify_event_ref')) {
            $pdo->exec('ALTER TABLE `ma_notify_task` ADD UNIQUE KEY `uk_notify_event_ref` (`event_type`, `ref_no`)');
        }
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
