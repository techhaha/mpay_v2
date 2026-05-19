<?php

namespace app\service\install;

use app\common\base\BaseService;
use PDO;
use RuntimeException;

/**
 * 安装配置和连接测试服务。
 */
class InstallConfigService extends BaseService
{
    /**
     * 创建 PDO 连接。
     *
     * @param array<string, mixed> $config 安装配置
     * @param bool $withDatabase 是否连接到指定数据库
     * @return PDO 数据库连接
     */
    public function createPdo(array $config, bool $withDatabase = true): PDO
    {
        $host = trim((string) ($config['db_host'] ?? '127.0.0.1'));
        $port = (int) ($config['db_port'] ?? 3306);
        $database = trim((string) ($config['db_database'] ?? ''));
        $username = trim((string) ($config['db_username'] ?? ''));
        $password = (string) ($config['db_password'] ?? '');
        $dbPart = $withDatabase && $database !== '' ? ';dbname=' . $database : '';
        $dsn = sprintf('mysql:host=%s;port=%d%s;charset=utf8mb4', $host, $port, $dbPart);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * 确保目标数据库存在。
     *
     * @param array<string, mixed> $config 数据库配置
     * @return void
     */
    public function ensureDatabase(array $config): void
    {
        $database = trim((string) ($config['db_database'] ?? ''));
        if ($database === '') {
            throw new RuntimeException('数据库名不能为空');
        }

        $pdo = $this->createPdo($config, false);
        $safeDatabase = str_replace('`', '``', $database);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDatabase}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    /**
     * 测试数据库连接、版本和基础建表能力。
     *
     * @param array<string, mixed> $config 数据库配置
     * @return array<string, mixed>
     */
    public function diagnoseDatabase(array $config): array
    {
        $this->ensureDatabase($config);
        $pdo = $this->createPdo($config);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $database = trim((string) ($config['db_database'] ?? ''));
        $probeTable = 'ma_install_probe_' . bin2hex(random_bytes(4));
        $checks = [];

        $checks[] = [
            'name' => '数据库连接',
            'status' => 'success',
            'message' => $version,
        ];

        try {
            $pdo->exec("CREATE TABLE `{$probeTable}` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, `payload` json DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("INSERT INTO `{$probeTable}` (`payload`) VALUES ('{\"ok\":true}')");
            $pdo->exec("DROP TABLE IF EXISTS `{$probeTable}`");
            $checks[] = ['name' => 'CREATE TABLE 权限', 'status' => 'success', 'message' => '通过'];
            $checks[] = ['name' => 'JSON 字段支持', 'status' => 'success', 'message' => '通过'];
            $checks[] = ['name' => 'utf8mb4 支持', 'status' => 'success', 'message' => '通过'];
        } catch (\Throwable $e) {
            $pdo->exec("DROP TABLE IF EXISTS `{$probeTable}`");
            $checks[] = ['name' => 'CREATE TABLE / JSON / utf8mb4', 'status' => 'error', 'message' => $e->getMessage()];
        }

        return [
            'version' => $version,
            'database' => $database,
            'checks' => $checks,
        ];
    }

    /**
     * 写入后端 .env 文件。
     *
     * @param array<string, mixed> $config 安装配置
     * @return void
     */
    public function writeEnv(array $config): void
    {
        $path = base_path(false) . DIRECTORY_SEPARATOR . '.env';
        $content = $this->renderEnv($config);

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('写入 .env 失败');
        }
    }

    /**
     * 测试 Redis 是否可用。
     *
     * @param array<string, mixed> $config Redis 配置
     * @return bool 是否通过
     */
    public function testRedis(array $config): bool
    {
        return (bool) $this->diagnoseRedis($config)['passed'];
    }

    /**
     * 测试 Redis 连接、写入和队列库选择能力。
     *
     * @param array<string, mixed> $config Redis 配置
     * @return array<string, mixed>
     */
    public function diagnoseRedis(array $config): array
    {
        if (!class_exists('Redis')) {
            throw new RuntimeException('Redis 扩展未启用');
        }

        $redis = new \Redis();
        $host = trim((string) ($config['redis_host'] ?? '127.0.0.1'));
        $port = (int) ($config['redis_port'] ?? 6379);
        $password = (string) ($config['redis_password'] ?? '');
        $database = (int) ($config['redis_database'] ?? 0);

        if (!$redis->connect($host, $port, 3.0)) {
            throw new RuntimeException('Redis 连接失败');
        }
        if ($password !== '' && !$redis->auth($password)) {
            throw new RuntimeException('Redis 认证失败');
        }
        $checks = [];
        if (!$redis->select($database)) {
            throw new RuntimeException('Redis 数据库选择失败: ' . $database);
        }

        $checks[] = ['name' => 'Redis PING', 'status' => 'success', 'message' => (string) $redis->ping()];

        $key = 'mpay:install:probe:' . bin2hex(random_bytes(4));
        if (!$redis->set($key, 'ok', 10)) {
            throw new RuntimeException('Redis 写入测试失败');
        }
        $value = (string) $redis->get($key);
        $redis->del($key);
        if ($value !== 'ok') {
            throw new RuntimeException('Redis 读写校验失败');
        }
        $checks[] = ['name' => 'Redis SET/GET/DEL', 'status' => 'success', 'message' => '通过'];

        $queueDatabase = (int) ($config['queue_database'] ?? 1);
        if (!$redis->select($queueDatabase)) {
            throw new RuntimeException('Queue DB 选择失败: ' . $queueDatabase);
        }
        $checks[] = ['name' => 'Queue DB 可选择', 'status' => 'success', 'message' => (string) $queueDatabase];

        return [
            'passed' => true,
            'checks' => $checks,
        ];
    }

    /**
     * 渲染安装生成的 .env 内容。
     *
     * @param array<string, mixed> $config 安装配置
     * @return string .env 内容
     */
    private function renderEnv(array $config): string
    {
        $lines = [
            '# 数据库配置',
            'DB_HOST=' . $this->envValue($config['db_host'] ?? '127.0.0.1'),
            'DB_PORT=' . $this->envValue($config['db_port'] ?? 3306),
            'DB_DATABASE=' . $this->envValue($config['db_database'] ?? 'mpay'),
            'DB_USERNAME=' . $this->envValue($config['db_username'] ?? 'root'),
            'DB_PASSWORD=' . $this->envValue($config['db_password'] ?? ''),
            '',
            '# Redis 配置',
            'REDIS_HOST=' . $this->envValue($config['redis_host'] ?? '127.0.0.1'),
            'REDIS_PORT=' . $this->envValue($config['redis_port'] ?? 6379),
            'REDIS_PASSWORD=' . $this->envValue($config['redis_password'] ?? ''),
            'REDIS_DATABASE=' . $this->envValue($config['redis_database'] ?? 0),
            'QUEUE_DATABASE=' . $this->envValue($config['queue_database'] ?? 1),
            '',
            '# 缓存配置',
            'CACHE_DRIVER=redis',
            '',
            '# JWT 配置',
            'AUTH_JWT_ISSUER=mpay',
            'AUTH_JWT_LEEWAY=30',
            'AUTH_JWT_SECRET=' . $this->envValue($config['auth_jwt_secret'] ?? ''),
            '',
            'AUTH_ADMIN_JWT_SECRET=' . $this->envValue($config['auth_admin_jwt_secret'] ?? ''),
            'AUTH_ADMIN_JWT_TTL=86400',
            'AUTH_ADMIN_JWT_REDIS_PREFIX=mpay:auth:admin:',
            '',
            'AUTH_MERCHANT_JWT_SECRET=' . $this->envValue($config['auth_merchant_jwt_secret'] ?? ''),
            'AUTH_MERCHANT_JWT_TTL=86400',
            'AUTH_MERCHANT_JWT_REDIS_PREFIX=mpay:auth:merchant:',
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * 转换 .env 字段值。
     *
     * @param mixed $value 原始值
     * @return string .env 安全文本
     */
    private function envValue(mixed $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_:\\/\\.\\-]+$/', $value)) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
