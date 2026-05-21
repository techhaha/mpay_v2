<?php

/**
 * 默认商户组与默认商户 Seeder。
 */
return new class {
    public string $name = 'default_merchant_seeder';

    private const DEFAULT_GROUP_ID = 1;
    private const DEFAULT_MERCHANT_ID = 1000;

    /**
     * 创建默认商户组、默认商户和基础账户。
     *
     * @param \PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<string, mixed> 执行摘要
     */
    public function run(\PDO $pdo, array $context = []): array
    {
        $this->seedGroup($pdo);
        $this->seedMerchant($pdo);
        $this->seedMerchantAccount($pdo);

        return [
            'merchant_group_id' => self::DEFAULT_GROUP_ID,
            'merchant_id' => self::DEFAULT_MERCHANT_ID,
            'merchant_no' => 'M20260521120046881802',
        ];
    }

    /**
     * 写入默认商户组。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    private function seedGroup(\PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO `ma_merchant_group` (`id`, `group_name`, `status`, `remark`, `created_at`, `updated_at`) ' .
            'VALUES (?, ?, 1, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `group_name` = VALUES(`group_name`), `status` = 1, `remark` = VALUES(`remark`), `updated_at` = NOW()'
        );
        $statement->execute([self::DEFAULT_GROUP_ID, '默认组', '安装程序创建']);
    }

    /**
     * 写入默认商户。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    private function seedMerchant(\PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO `ma_merchant` (`id`, `merchant_no`, `password_hash`, `merchant_name`, `merchant_short_name`, `merchant_type`, `group_id`, `risk_level`, ' .
            '`contact_name`, `contact_phone`, `contact_email`, `settlement_account_name`, `settlement_account_no`, `settlement_bank_name`, `settlement_bank_branch`, ' .
            '`status`, `pay_status`, `settle_status`, `settle_type`, `last_login_ip`, `password_updated_at`, `remark`, `created_at`, `updated_at`) ' .
            'VALUES (?, ?, ?, ?, ?, 1, ?, 0, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 4, "", NOW(), ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `merchant_name` = VALUES(`merchant_name`), `merchant_short_name` = VALUES(`merchant_short_name`), `group_id` = VALUES(`group_id`), ' .
            '`contact_name` = VALUES(`contact_name`), `contact_phone` = VALUES(`contact_phone`), `contact_email` = VALUES(`contact_email`), ' .
            '`settlement_account_name` = VALUES(`settlement_account_name`), `settlement_account_no` = VALUES(`settlement_account_no`), `settlement_bank_name` = VALUES(`settlement_bank_name`), ' .
            '`settlement_bank_branch` = VALUES(`settlement_bank_branch`), `status` = 1, `pay_status` = 1, `settle_status` = 1, `settle_type` = 4, `remark` = VALUES(`remark`), `updated_at` = NOW()'
        );
        $statement->execute([
            self::DEFAULT_MERCHANT_ID,
            'M20260521120046881802',
            '$2y$10$l7w4OZT1W2JWxztVKZqT8OJizbx1FG2c6gXlOWR2PjnnlowLJyZwC',
            '青腾信息科技有限公司',
            '青腾科技',
            self::DEFAULT_GROUP_ID,
            '技术老胡',
            '18866664444',
            'laohu@qq.com',
            '老胡',
            '44445555666677778888',
            '中国银行',
            '中国银行北京支行',
            '测试商户',
        ]);
    }

    /**
     * 写入默认商户账户。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    private function seedMerchantAccount(\PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO `ma_merchant_account` (`merchant_id`, `available_balance`, `frozen_balance`, `created_at`, `updated_at`) VALUES (?, 0, 0, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `updated_at` = NOW()'
        );
        $statement->execute([self::DEFAULT_MERCHANT_ID]);
    }
};
