<?php

namespace app\command;

use app\service\database\MigrationRunner;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('migrate:status', '查看数据库迁移状态')]
class MigrateStatus extends Command
{
    /**
     * 输出数据库迁移状态。
     *
     * @param InputInterface $input 命令输入
     * @param OutputInterface $output 命令输出
     * @return int 命令退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            foreach ((new MigrationRunner())->status($this->pdo()) as $row) {
                $output->writeln(sprintf(
                    '%s  %s  %s  batch=%s',
                    $row['status'] === 'executed' ? '<info>executed</info>' : '<comment>pending </comment>',
                    $row['version'],
                    $row['name'],
                    $row['batch'] ?? '-'
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>查询迁移状态失败：' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }
    }

    /**
     * 创建数据库连接。
     *
     * @return PDO 数据库连接
     */
    private function pdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_DATABASE', 'mpay')
        );

        return new PDO($dsn, env('DB_USERNAME', 'root'), env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
