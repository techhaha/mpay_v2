<?php

/**
 * 管理员账号 Seeder。
 */
return new class {
    public string $name = 'admin_user_seeder';

    /**
     * 创建或更新安装管理员账号。
     *
     * @param \PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<string, string> 执行摘要
     */
    public function run(\PDO $pdo, array $context = []): array
    {
        $install = (array) ($context['install'] ?? []);
        $username = trim((string) ($install['admin_username'] ?? 'admin'));
        $password = (string) ($install['admin_password'] ?? '');
        $realName = trim((string) ($install['admin_real_name'] ?? '超级管理员'));

        if ($password === '') {
            throw new \RuntimeException('管理员密码不能为空');
        }

        $statement = $pdo->prepare(
            'INSERT INTO `ma_admin_user` (`username`, `password_hash`, `real_name`, `is_super`, `status`, `remark`, `created_at`, `updated_at`) ' .
            'VALUES (?, ?, ?, 1, 1, "安装程序创建", NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`), `real_name` = VALUES(`real_name`), `is_super` = 1, `status` = 1, `updated_at` = NOW()'
        );
        $statement->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $realName,
        ]);

        return ['username' => $username];
    }
};
