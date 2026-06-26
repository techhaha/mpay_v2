<?php

/**
 * 为订单列表定向搜索补充单号索引。
 */
return new class {
    /**
     * 迁移版本号。
     *
     * @var string
     */
    public string $version = '202606260001';

    /**
     * 迁移名称。
     *
     * @var string
     */
    public string $name = 'add_order_search_indexes';

    /**
     * 执行迁移。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        $this->addIndex($pdo, 'ma_biz_order', 'idx_merchant_order_no', '`merchant_order_no`');
        $this->addIndex($pdo, 'ma_pay_order', 'idx_channel_order_no', '`channel_order_no`');
        $this->addIndex($pdo, 'ma_pay_order', 'idx_channel_request_no', '`channel_request_no`');
        $this->addIndex($pdo, 'ma_refund_order', 'idx_merchant_refund_no', '`merchant_refund_no`');
        $this->addIndex($pdo, 'ma_refund_order', 'idx_channel_refund_no', '`channel_refund_no`');
        $this->addIndex($pdo, 'ma_refund_order', 'idx_channel_request_no', '`channel_request_no`');
    }

    /**
     * 补充普通索引，已存在时跳过，方便开发库反复执行。
     *
     * @param \PDO $pdo 数据库连接
     * @param string $table 表名
     * @param string $indexName 索引名
     * @param string $columns 索引字段 SQL 片段
     * @return void
     */
    private function addIndex(\PDO $pdo, string $table, string $indexName, string $columns): void
    {
        if ($this->indexExists($pdo, $table, $indexName)) {
            return;
        }

        $pdo->exec(sprintf('ALTER TABLE `%s` ADD KEY `%s` (%s)', $table, $indexName, $columns));
    }

    /**
     * 判断指定索引是否已经存在。
     *
     * @param \PDO $pdo 数据库连接
     * @param string $table 表名
     * @param string $indexName 索引名
     * @return bool
     */
    private function indexExists(\PDO $pdo, string $table, string $indexName): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $indexName]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
