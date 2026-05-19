<?php

/**
 * 初始数据库结构迁移。
 */
return new class {
    public string $version = '202605170001';
    public string $name = 'initial_schema';

    /**
     * 执行初始结构导入。
     *
     * @param \PDO $pdo 数据库连接
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        $sqlPath = base_path(false) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR . 'payment-middle-ddl.sql';
        if (!is_file($sqlPath)) {
            $sqlPath = dirname(base_path(false)) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'payment-middle-ddl.sql';
        }
        if (!is_file($sqlPath)) {
            throw new \RuntimeException('未找到初始结构快照: ' . $sqlPath);
        }

        $sql = (string) file_get_contents($sqlPath);
        foreach ($this->statements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * 拆分 SQL 文件中的可执行语句。
     *
     * @param string $sql SQL 文本
     * @return array<int, string>
     */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?: $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        $statements = [];

        foreach ($parts as $part) {
            $statement = trim($part);
            if ($statement === '') {
                continue;
            }

            $statements[] = $statement;
        }

        return $statements;
    }
};
