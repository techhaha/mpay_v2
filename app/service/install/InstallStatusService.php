<?php

namespace app\service\install;

use app\common\base\BaseService;
use PDO;

/**
 * 安装状态服务。
 */
class InstallStatusService extends BaseService
{
    /**
     * 构造方法。
     *
     * @param InstallLockService $lockService 安装锁服务
     * @param InstallConfigService $configService 安装配置服务
     * @return void
     */
    public function __construct(
        protected InstallLockService $lockService,
        protected InstallConfigService $configService
    ) {
    }

    /**
     * 获取当前安装状态。
     *
     * @return array<string, mixed> 安装状态
     */
    public function status(): array
    {
        $databaseInstalled = false;
        $databaseExists = false;
        $coreTables = [];

        try {
            if (is_file(base_path(false) . DIRECTORY_SEPARATOR . '.env')) {
                $pdo = $this->configService->createPdo([
                    'db_host' => env('DB_HOST', '127.0.0.1'),
                    'db_port' => env('DB_PORT', '3306'),
                    'db_database' => env('DB_DATABASE', 'mpay'),
                    'db_username' => env('DB_USERNAME', 'root'),
                    'db_password' => env('DB_PASSWORD', ''),
                ]);
                $statement = $pdo->prepare("SELECT `config_value` FROM `ma_system_config` WHERE `config_key` = 'install_status' LIMIT 1");
                $statement->execute();
                $databaseInstalled = (string) $statement->fetchColumn() === 'installed';
                $coreTables = $this->coreTables($pdo);
                $databaseExists = $coreTables !== [];
            }
        } catch (\Throwable) {
            $databaseInstalled = false;
        }

        return [
            'installed' => $this->lockService->exists() || $databaseInstalled,
            'lock_file_exists' => $this->lockService->exists(),
            'database_installed' => $databaseInstalled,
            'database_exists' => $databaseExists,
            'core_tables' => $coreTables,
            'suspicious_existing_data' => !$databaseInstalled && !$this->lockService->exists() && $databaseExists,
            'lock_path' => $this->lockService->lockPath(),
        ];
    }

    /**
     * 检测核心业务表是否存在。
     *
     * @param PDO $pdo 数据库连接
     * @return array<int, string>
     */
    private function coreTables(PDO $pdo): array
    {
        $tables = ['ma_admin_user', 'ma_pay_order', 'ma_system_config', 'ma_payment_plugin'];
        $found = [];
        $statement = $pdo->prepare('SHOW TABLES LIKE ?');

        foreach ($tables as $table) {
            $statement->execute([$table]);
            if ($statement->fetchColumn()) {
                $found[] = $table;
            }
        }

        return $found;
    }
}
