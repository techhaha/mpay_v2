<?php

namespace app\service\database;

use app\common\base\BaseService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 轻量数据库迁移执行器。
 *
 * 安装和后续升级共用该执行器；迁移文件按文件名前缀排序，每个版本只执行一次。
 */
class MigrationRunner extends BaseService
{
    /**
     * 迁移文件目录。
     *
     * @var string
     */
    private string $migrationPath;

    /**
     * 构造方法。
     *
     * @param string|null $migrationPath 迁移文件目录
     * @return void
     */
    public function __construct(?string $migrationPath = null)
    {
        $this->migrationPath = $migrationPath ?: base_path(false) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * 执行所有未运行的迁移。
     *
     * @param PDO $pdo 数据库连接
     * @return array{executed: array<int, array<string, string>>, skipped: array<int, array<string, string>>, batch: int}
     */
    public function run(PDO $pdo): array
    {
        $this->ensureMigrationTable($pdo);

        $executedVersions = $this->executedVersions($pdo);
        $batch = $this->nextBatch($pdo);
        $result = [
            'executed' => [],
            'skipped' => [],
            'batch' => $batch,
        ];

        foreach ($this->migrationFiles() as $file) {
            $migration = require $file;
            $version = $this->migrationVersion($migration, $file);
            $name = $this->migrationName($migration, $file);

            if (isset($executedVersions[$version])) {
                $result['skipped'][] = ['version' => $version, 'name' => $name];
                continue;
            }

            if (!is_object($migration) || !method_exists($migration, 'up')) {
                throw new RuntimeException('迁移文件缺少 up() 方法: ' . $file);
            }

            try {
                $migration->up($pdo);
                $this->record($pdo, $version, $name, $batch);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw new RuntimeException(sprintf('迁移失败 [%s] %s: %s', $version, $name, $e->getMessage()), 0, $e);
            }

            $result['executed'][] = ['version' => $version, 'name' => $name];
        }

        return $result;
    }

    /**
     * 获取迁移状态。
     *
     * @param PDO $pdo 数据库连接
     * @return array<int, array{version: string, name: string, status: string, batch: int|null, executed_at: string|null}>
     */
    public function status(PDO $pdo): array
    {
        $this->ensureMigrationTable($pdo);
        $executed = $this->executedRows($pdo);
        $rows = [];

        foreach ($this->migrationFiles() as $file) {
            $migration = require $file;
            $version = $this->migrationVersion($migration, $file);
            $name = $this->migrationName($migration, $file);
            $current = $executed[$version] ?? null;

            $rows[] = [
                'version' => $version,
                'name' => $name,
                'status' => $current ? 'executed' : 'pending',
                'batch' => $current ? (int) $current['batch'] : null,
                'executed_at' => $current['executed_at'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * 确保迁移记录表存在。
     *
     * @param PDO $pdo 数据库连接
     * @return void
     */
    private function ensureMigrationTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ma_migrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `batch` int unsigned NOT NULL DEFAULT 1,
  `executed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库迁移记录表'
SQL);
    }

    /**
     * 获取迁移文件列表。
     *
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * 获取已执行迁移版本集合。
     *
     * @param PDO $pdo 数据库连接
     * @return array<string, bool>
     */
    private function executedVersions(PDO $pdo): array
    {
        return array_fill_keys(array_keys($this->executedRows($pdo)), true);
    }

    /**
     * 获取已执行迁移记录。
     *
     * @param PDO $pdo 数据库连接
     * @return array<string, array<string, mixed>>
     */
    private function executedRows(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT `version`, `name`, `batch`, `executed_at` FROM `ma_migrations` ORDER BY `version`');
        $rows = $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['version']] = $row;
        }

        return $map;
    }

    /**
     * 计算下一批次编号。
     *
     * @param PDO $pdo 数据库连接
     * @return int 批次编号
     */
    private function nextBatch(PDO $pdo): int
    {
        $value = $pdo->query('SELECT MAX(`batch`) FROM `ma_migrations`')->fetchColumn();

        return max(1, (int) $value + 1);
    }

    /**
     * 写入迁移执行记录。
     *
     * @param PDO $pdo 数据库连接
     * @param string $version 迁移版本
     * @param string $name 迁移名称
     * @param int $batch 批次编号
     * @return void
     */
    private function record(PDO $pdo, string $version, string $name, int $batch): void
    {
        $statement = $pdo->prepare('INSERT INTO `ma_migrations` (`version`, `name`, `batch`, `executed_at`) VALUES (?, ?, ?, NOW())');
        $statement->execute([$version, $name, $batch]);
    }

    /**
     * 解析迁移版本。
     *
     * @param object $migration 迁移对象
     * @param string $file 迁移文件
     * @return string 迁移版本
     */
    private function migrationVersion(object $migration, string $file): string
    {
        $version = trim((string) ($migration->version ?? ''));
        if ($version !== '') {
            return $version;
        }

        return preg_replace('/[^0-9]/', '', pathinfo($file, PATHINFO_FILENAME)) ?: pathinfo($file, PATHINFO_FILENAME);
    }

    /**
     * 解析迁移名称。
     *
     * @param object $migration 迁移对象
     * @param string $file 迁移文件
     * @return string 迁移名称
     */
    private function migrationName(object $migration, string $file): string
    {
        $name = trim((string) ($migration->name ?? ''));

        return $name !== '' ? $name : pathinfo($file, PATHINFO_FILENAME);
    }
}
