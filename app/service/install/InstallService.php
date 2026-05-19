<?php

namespace app\service\install;

use app\common\base\BaseService;
use app\service\database\MigrationRunner;
use app\service\database\SeederRunner;
use PDO;
use RuntimeException;

/**
 * 安装编排服务。
 */
class InstallService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param InstallStatusService $statusService 安装状态服务
     * @param InstallConfigService $configService 安装配置服务
     * @param MigrationRunner $migrationRunner 数据库迁移执行器
     * @param SeederRunner $seederRunner 基础数据填充执行器
     * @param KeyGeneratorService $keyGenerator 密钥生成服务
     * @param InstallLockService $lockService 安装锁服务
     * @return void
     */
    public function __construct(
        protected InstallStatusService $statusService,
        protected InstallConfigService $configService,
        protected MigrationRunner $migrationRunner,
        protected SeederRunner $seederRunner,
        protected KeyGeneratorService $keyGenerator,
        protected InstallLockService $lockService
    ) {
    }

    /**
     * 执行安装流程。
     *
     * @param array<string, mixed> $payload 安装表单数据
     * @return array<string, mixed> 安装结果
     */
    public function run(array $payload): array
    {
        if ($this->statusService->status()['installed']) {
            throw new RuntimeException('系统已安装，如需重装请手动清理安装锁和数据库');
        }

        $config = $this->normalize($payload);
        $this->ensureRuntimeDirectories();
        $this->configService->ensureDatabase($config);
        $this->configService->writeEnv($config);

        $pdo = $this->configService->createPdo($config);
        $migrations = $this->migrationRunner->run($pdo);
        $seeders = $this->seederRunner->run($pdo, [
            'install' => $config,
            'agreement_version' => '1.0',
        ]);
        $keys = $this->keyGenerator->writePlatformKeys(false);
        $this->writeInstallConfig($pdo, $config);

        $lockPayload = [
            'installed_at' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'site_name' => $config['site_name'],
            'site_url' => $config['site_url'],
        ];
        $this->lockService->write($lockPayload);

        return [
            'migrations' => $migrations,
            'seeders' => $seeders,
            'keys' => $keys,
            'lock' => $lockPayload,
            'admin_url' => '/admin',
            'restart_required' => true,
        ];
    }

    /**
     * 标准化并校验安装参数。
     *
     * @param array<string, mixed> $payload 安装表单数据
     * @return array<string, mixed> 标准化配置
     */
    private function normalize(array $payload): array
    {
        $required = [
            'site_name',
            'site_url',
            'db_host',
            'db_port',
            'db_database',
            'db_username',
            'redis_host',
            'redis_port',
            'admin_username',
            'admin_password',
        ];

        foreach ($required as $key) {
            if (trim((string) ($payload[$key] ?? '')) === '') {
                throw new RuntimeException('缺少必要参数: ' . $key);
            }
        }

        if (strlen((string) $payload['admin_password']) < 8) {
            throw new RuntimeException('管理员密码至少 8 位');
        }
        if ((string) $payload['admin_password'] !== (string) ($payload['admin_password_confirm'] ?? '')) {
            throw new RuntimeException('两次输入的管理员密码不一致');
        }

        return [
            'site_name' => trim((string) $payload['site_name']),
            'site_url' => rtrim(trim((string) $payload['site_url']), '/'),
            'db_host' => trim((string) $payload['db_host']),
            'db_port' => (int) $payload['db_port'],
            'db_database' => trim((string) $payload['db_database']),
            'db_username' => trim((string) $payload['db_username']),
            'db_password' => (string) ($payload['db_password'] ?? ''),
            'redis_host' => trim((string) $payload['redis_host']),
            'redis_port' => (int) $payload['redis_port'],
            'redis_password' => (string) ($payload['redis_password'] ?? ''),
            'redis_database' => (int) ($payload['redis_database'] ?? 0),
            'queue_database' => (int) ($payload['queue_database'] ?? 1),
            'admin_username' => trim((string) $payload['admin_username']),
            'admin_password' => (string) $payload['admin_password'],
            'admin_real_name' => trim((string) ($payload['admin_real_name'] ?? '超级管理员')),
            'auth_jwt_secret' => trim((string) ($payload['auth_jwt_secret'] ?? '')) ?: $this->keyGenerator->randomSecret(),
            'auth_admin_jwt_secret' => trim((string) ($payload['auth_admin_jwt_secret'] ?? '')) ?: $this->keyGenerator->randomSecret(),
            'auth_merchant_jwt_secret' => trim((string) ($payload['auth_merchant_jwt_secret'] ?? '')) ?: $this->keyGenerator->randomSecret(),
        ];
    }

    /**
     * 确保安装所需 runtime 和公开存储目录存在。
     *
     * @return void
     */
    private function ensureRuntimeDirectories(): void
    {
        $directories = [
            base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
            base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'logs',
            base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache',
            base_path(false) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'storage',
            public_path('storage'),
            public_path('storage/uploads'),
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException('创建目录失败: ' . $directory);
            }
        }
    }

    /**
     * 写入安装状态配置。
     *
     * @param PDO $pdo 数据库连接
     * @param array<string, mixed> $config 安装配置
     * @return void
     */
    private function writeInstallConfig(PDO $pdo, array $config): void
    {
        $items = [
            'install_status' => 'installed',
            'install_version' => '1.0',
            'install_time' => date('Y-m-d H:i:s'),
            'install_agreement_version' => '1.0',
            'site_name' => $config['site_name'],
            'site_url' => $config['site_url'],
        ];

        $statement = $pdo->prepare(
            'INSERT INTO `ma_system_config` (`config_key`, `config_value`, `group_code`, `created_at`, `updated_at`) VALUES (?, ?, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`), `group_code` = VALUES(`group_code`), `updated_at` = NOW()'
        );

        foreach ($items as $key => $value) {
            $statement->execute([$key, $value, 'install']);
        }
    }
}
