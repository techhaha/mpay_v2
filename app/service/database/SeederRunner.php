<?php

namespace app\service\database;

use app\common\base\BaseService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 基础数据填充执行器。
 */
class SeederRunner extends BaseService
{
    /**
     * Seeder 文件目录。
     *
     * @var string
     */
    private string $seederPath;

    /**
     * 构造方法。
     *
     * @param string|null $seederPath Seeder 文件目录
     * @return void
     */
    public function __construct(?string $seederPath = null)
    {
        $this->seederPath = $seederPath ?: base_path(false) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';
    }

    /**
     * 执行全部 Seeder。Seeder 必须幂等。
     *
     * @param PDO $pdo 数据库连接
     * @param array<string, mixed> $context 安装上下文
     * @return array<int, array{name: string, result: mixed}>
     */
    public function run(PDO $pdo, array $context = []): array
    {
        $files = glob($this->seederPath . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $results = [];

        foreach ($files as $file) {
            $seeder = require $file;
            if (!is_object($seeder) || !method_exists($seeder, 'run')) {
                throw new RuntimeException('Seeder 文件缺少 run() 方法: ' . $file);
            }

            $name = trim((string) ($seeder->name ?? pathinfo($file, PATHINFO_FILENAME)));

            try {
                $results[] = [
                    'name' => $name,
                    'result' => $seeder->run($pdo, $context),
                ];
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf('Seeder 执行失败 [%s]: %s', $name, $e->getMessage()), 0, $e);
            }
        }

        return $results;
    }
}
