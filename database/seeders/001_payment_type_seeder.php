<?php

/**
 * 支付方式基础数据 Seeder。
 */
return new class {
    public string $name = 'payment_type_seeder';

    /**
     * 写入内置支付方式。
     *
     * @param \PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<string, int> 执行摘要
     */
    public function run(\PDO $pdo, array $context = []): array
    {
        $rows = [
            ['alipay', '支付宝', 'icon-alipay', 10, '支付宝支付'],
            ['wxpay', '微信支付', 'icon-wechat', 20, '微信支付'],
            ['qqpay', 'QQ钱包', 'icon-qq', 30, 'QQ 钱包支付'],
            ['bank', '银行卡', 'icon-bank-card', 40, '银行卡支付'],
        ];

        $statement = $pdo->prepare(
            'INSERT INTO `ma_payment_type` (`code`, `name`, `icon`, `sort_no`, `status`, `remark`, `created_at`, `updated_at`) ' .
            'VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `icon` = VALUES(`icon`), `sort_no` = VALUES(`sort_no`), `updated_at` = NOW()'
        );

        foreach ($rows as $row) {
            $statement->execute($row);
        }

        return ['count' => count($rows)];
    }
};
